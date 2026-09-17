<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */

/*
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace App\Service\Translation;

use AnimeDb\PluginContracts\Manifest\PluginType;
use App\Service\Plugin\InstalledPlugin;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Translation\Exception\NativeTranslationsOverlayException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds the userData overlay `native/i18n` merges on top of the built-in `native/translations/`
 * catalogs (issue #646) from every enabled {@see PluginType::Translation} plugin's own
 * `translations/native/<locale>.json` (issue #647) — one flattened JSON file per locale, written
 * to `$nativeTranslationsOverlayDir`. `native/` itself never learns anything about plugins or
 * `plugins.json`; it
 * only ever reads the already-built overlay file for the locale it needs (see
 * `.claude-docs/decisions.md`, issue #404 entry).
 *
 * Called exactly once, from the very end of {@see InstalledPluginsRegistry::reconcile()}'s own
 * `synchronized()` callback — the single funnel every plugin install, update, remove and rollback
 * path already goes through (issue #420). This class deliberately has no other entry point and is
 * never called directly from {@see \App\Service\Plugin\ZipPluginInstaller} or
 * {@see \App\Service\Plugin\PluginRemover}.
 *
 * `$registry` here is always the container-autowired {@see InstalledPluginsRegistry} singleton,
 * whose `safeMode` is always `false` (nothing in `config/services.yaml` binds it) — never the
 * safe-mode-aware instance {@see \App\Kernel} constructs by hand for pre-container bundle
 * registration, which is never reachable through the DI container at all and whose `reconcile()`
 * is in fact never called. Injecting it as a constructor dependency of
 * {@see InstalledPluginsRegistry} itself, rather than reading `enabled()` off of `$this` inside
 * `reconcile()`, is what keeps that guarantee true regardless of which registry instance's
 * `reconcile()` happens to trigger a write — see that constructor's `$overlayWriter` parameter for
 * why it is wired lazily (this dependency would otherwise be circular: this registry the writer
 * reads from is, in the running app, the very same singleton that owns the writer).
 *
 * Sanitizes every value against two rules before it can reach disk: only keys present in the
 * built-in `translations/native/en.json` reference pass ({@see self::readReferenceKeys()}), and
 * only string values no longer than {@see self::MAX_VALUE_LENGTH} characters — see that constant
 * for why. A locale with nothing left after sanitizing gets no overlay file at all, not an empty
 * one; `native/i18n`'s own per-key merge already treats "no overlay" and "empty overlay" the same
 * way, so writing an empty file would only be one more thing to clean up later.
 *
 * A plugin's `translations/native/` collision on the same (locale, key) is resolved by iteration
 * order alone: {@see InstalledPluginsRegistry::enabled()} yields plugins in ascending-id order
 * (inherited from how `reconcile()` itself rebuilds the index, see that method's docblock), and
 * this class merges each plugin's catalog over the accumulator for its locale in that same order —
 * the later (higher) plugin id wins on a shared key, the same winner Symfony's own translator
 * resolves to for the `messages` domain (`.claude-docs/decisions.md`, issue #451 entry). No
 * separate collision-resolution code exists on top of that; see {@see self::mergeByLocale()}.
 */
final class NativeTranslationsOverlayWriter
{
    /**
     * A splash-step translation ends up as a command-line argument to the renderer process
     * (native/window/index.js), and Windows caps a process's whole command line at 32767
     * characters. The longest built-in string today is around 400 characters; 1000 leaves ample
     * headroom for a plugin's own translation while staying an order of magnitude under the
     * platform limit. A value over this is dropped and logged, never truncated — a truncated
     * translation reads as a bug, a missing one just falls back through the chain
     * {@see \App\Service\Plugin\AvailableLocalesProvider} and `native/i18n#resolveCatalog()`
     * already provide.
     */
    private const int MAX_VALUE_LENGTH = 1000;

    public function __construct(
        private readonly string $nativeTranslationsDir,
        private readonly string $nativeTranslationsOverlayDir,
        private readonly InstalledPluginsRegistry $registry,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @throws NativeTranslationsOverlayException if the reference catalog cannot be read, or an
     *                                            overlay file cannot be written, renamed or
     *                                            removed — both are meant to propagate out of
     *                                            {@see InstalledPluginsRegistry::reconcile()} and
     *                                            fail the install/update/remove operation that
     *                                            triggered it
     */
    public function write(): void
    {
        // Read and validated before anything else touches disk: an unreadable reference must
        // leave a previously written overlay exactly as it was, not half-rebuilt against an
        // incomplete key set — see the class docblock and the exception's own.
        $referenceKeys = $this->readReferenceKeys();

        $catalogsByLocale = [];
        foreach ($this->registry->enabled() as $plugin) {
            if ($plugin->manifest->type !== PluginType::Translation) {
                continue;
            }

            $catalogsByLocale = $this->mergeByLocale($catalogsByLocale, $plugin);
        }

        $sanitizedByLocale = [];
        foreach ($catalogsByLocale as $locale => $catalog) {
            $sanitized = $this->sanitize($catalog, $referenceKeys, $locale);
            if ($sanitized !== []) {
                $sanitizedByLocale[$locale] = $sanitized;
            }
        }

        $this->writeOverlayFiles($sanitizedByLocale);
    }

    /**
     * @param array<string, array<string, mixed>> $catalogsByLocale accumulator, keyed by locale
     *
     * @return array<string, array<string, mixed>>
     */
    private function mergeByLocale(array $catalogsByLocale, InstalledPlugin $plugin): array
    {
        foreach ($this->readPluginNativeCatalogs($plugin) as $locale => $catalog) {
            // array_merge(), not the union operator: a later plugin's value for a key already
            // seen from an earlier one must win, and array_merge() gives the second argument
            // priority on a shared string key — the union operator would instead keep the first
            // argument's value, the opposite of the ordering rule this class documents.
            $catalogsByLocale[$locale] = array_merge($catalogsByLocale[$locale] ?? [], $catalog);
        }

        return $catalogsByLocale;
    }

    /**
     * @return array<string, true> a set — only key presence is ever checked
     *
     * @throws NativeTranslationsOverlayException
     */
    private function readReferenceKeys(): array
    {
        $path = $this->nativeTranslationsDir.\DIRECTORY_SEPARATOR.'en.json';
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new NativeTranslationsOverlayException(\sprintf('Unable to read the reference native translations catalog "%s".', $path));
        }

        $decoded = json_decode($contents, true);
        if (!\is_array($decoded)) {
            throw new NativeTranslationsOverlayException(\sprintf('Reference native translations catalog "%s" is not a valid JSON object.', $path));
        }

        return array_fill_keys(array_map('strval', array_keys($decoded)), true);
    }

    /**
     * A missing `translations/native/` directory is not an error — most translation plugins ship
     * none, the native layer's own catalogs (splash/tray/dialogs) are a small surface most
     * language packs have no reason to cover. A directory that exists but cannot be scanned, or a
     * `<locale>.json` file inside it that cannot be read or does not decode to a JSON object, is
     * logged and skipped instead of thrown — this plugin is, from this method's point of view,
     * always a bystander: the plugin currently being installed or updated already had its own
     * `translations/native/` validated before ever reaching {@see InstalledPluginsRegistry::reconcile()}
     * — see {@see \App\Service\Plugin\ZipPluginInstaller::assertNativeTranslationsAreReadable()}.
     *
     * @return iterable<string, array<string, mixed>> locale => raw decoded catalog, not yet
     *                                                sanitized
     */
    private function readPluginNativeCatalogs(InstalledPlugin $plugin): iterable
    {
        $dir = $plugin->installPath.\DIRECTORY_SEPARATOR.'translations'.\DIRECTORY_SEPARATOR.'native';
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir.\DIRECTORY_SEPARATOR.'*.json');
        if ($files === false) {
            $this->logger->error('Unable to scan a plugin\'s native translations directory; skipping it.', [
                'pluginId' => (string) $plugin->id,
                'dir' => $dir,
            ]);

            return;
        }

        foreach ($files as $file) {
            $locale = basename($file, '.json');
            $contents = is_file($file) ? file_get_contents($file) : false;
            if ($contents === false) {
                $this->logger->error('Unable to read a plugin\'s native translations catalog; skipping it.', [
                    'pluginId' => (string) $plugin->id,
                    'file' => $file,
                ]);

                continue;
            }

            $decoded = json_decode($contents, true);
            if (!\is_array($decoded)) {
                $this->logger->error('A plugin\'s native translations catalog is not a valid JSON object; skipping it.', [
                    'pluginId' => (string) $plugin->id,
                    'file' => $file,
                ]);

                continue;
            }

            yield $locale => $decoded;
        }
    }

    /**
     * @param array<mixed, mixed> $catalog
     * @param array<string, true> $referenceKeys
     *
     * @return array<string, string>
     */
    private function sanitize(array $catalog, array $referenceKeys, string $locale): array
    {
        $sanitized = [];
        foreach ($catalog as $key => $value) {
            if (!\is_string($key) || !isset($referenceKeys[$key]) || !\is_string($value)) {
                continue;
            }

            if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
                $this->logger->warning('Dropping an overlong native translation overlay value.', [
                    'locale' => $locale,
                    'key' => $key,
                    'length' => mb_strlen($value),
                ]);

                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * @param array<string, array<string, string>> $sanitizedByLocale
     *
     * @throws NativeTranslationsOverlayException
     */
    private function writeOverlayFiles(array $sanitizedByLocale): void
    {
        if (!is_dir($this->nativeTranslationsOverlayDir) && !mkdir($this->nativeTranslationsOverlayDir, recursive: true) && !is_dir($this->nativeTranslationsOverlayDir)) {
            throw new NativeTranslationsOverlayException(\sprintf('Unable to create directory "%s".', $this->nativeTranslationsOverlayDir));
        }

        // Read before any file below is touched: writeOverlayFile() calls further down would
        // otherwise make an overlay file it just wrote look "stale" to this same pass and delete
        // it again immediately.
        $staleLocales = array_diff($this->existingOverlayLocales(), array_keys($sanitizedByLocale));

        foreach ($sanitizedByLocale as $locale => $catalog) {
            $this->writeOverlayFile($locale, $catalog);
        }

        foreach ($staleLocales as $staleLocale) {
            $this->removeOverlayFile($staleLocale);
        }
    }

    /**
     * @return list<string>
     */
    private function existingOverlayLocales(): array
    {
        $files = is_dir($this->nativeTranslationsOverlayDir) ? glob($this->nativeTranslationsOverlayDir.\DIRECTORY_SEPARATOR.'*.json') : [];
        if ($files === false) {
            return [];
        }

        return array_map(static fn (string $file): string => basename($file, '.json'), $files);
    }

    /**
     * Same shape as {@see InstalledPluginsRegistry::writeIndex()}: a random-suffixed temp file
     * next to the target plus `rename()` over it, not {@see \App\Service\AppConfigStore}'s fixed
     * temp name — that one is only safe because every writer also holds an `flock()` for the
     * whole operation, which this class has no equivalent of (it runs inside
     * {@see InstalledPluginsRegistry::synchronized()}'s lock, but that guards the whole overlay
     * directory's rebuild, not a single file in isolation the way `AppConfigStore` needs).
     *
     * @param array<string, string> $catalog
     *
     * @throws NativeTranslationsOverlayException
     */
    private function writeOverlayFile(string $locale, array $catalog): void
    {
        $path = $this->overlayPath($locale);
        $contents = json_encode($catalog, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        $tmpPath = $path.'.tmp.'.bin2hex(random_bytes(8));
        if (file_put_contents($tmpPath, $contents, \LOCK_EX) === false) {
            throw new NativeTranslationsOverlayException(\sprintf('Unable to write "%s".', $tmpPath));
        }

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);

            throw new NativeTranslationsOverlayException(\sprintf('Unable to rename "%s" to "%s".', $tmpPath, $path));
        }
    }

    /**
     * @throws NativeTranslationsOverlayException
     */
    private function removeOverlayFile(string $locale): void
    {
        $path = $this->overlayPath($locale);
        if (is_file($path) && !@unlink($path)) {
            throw new NativeTranslationsOverlayException(\sprintf('Unable to remove "%s".', $path));
        }
    }

    private function overlayPath(string $locale): string
    {
        return $this->nativeTranslationsOverlayDir.\DIRECTORY_SEPARATOR.$locale.'.json';
    }
}
