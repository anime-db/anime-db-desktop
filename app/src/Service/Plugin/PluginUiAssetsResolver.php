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

namespace App\Service\Plugin;

use App\Entity\ValueObject\PluginId;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Resolves a plugin's manifest-declared `ui.css`/`ui.js` paths (issue #604) into URLs the page
 * shell can drop straight into `<link>`/`<script>` tags, for {@see \App\Controller\AnimeController}
 * and {@see \App\Controller\Settings\PluginSettingsController} — the two places that inject a
 * plugin's declared UI into a host-rendered page rather than the plugin's own markup.
 *
 * Unlike {@see \App\Twig\PluginAssetExtension::generate()} (used by a plugin's own markup, where a
 * missing asset is that markup's own bug and is handled by whichever error boundary already wraps
 * it — {@see \App\Controller\PluginWidgetController} for a widget), a declared asset that fails to
 * resolve here must never throw: this class is invoked while building a page shell that has no
 * such boundary around it, so a third-party plugin shipping a stale or malformed `ui` entry (a
 * routine possibility — arbitrary-zip install, issue #251, does not verify a declared file exists)
 * must not be able to take the whole page down. The offending entry is skipped and logged instead.
 */
final class PluginUiAssetsResolver
{
    public function __construct(
        private readonly PluginAssetResolver $assets,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{css: list<string>, js: list<string>}
     */
    public function resolve(PluginId $id): array
    {
        $plugin = $this->assets->findEnabledPlugin($id);
        $ui = $plugin?->manifest->ui;
        if ($plugin === null || $ui === null) {
            return ['css' => [], 'js' => []];
        }

        return [
            'css' => $this->resolveAll($plugin, $id, $ui->css),
            'js' => $this->resolveAll($plugin, $id, $ui->js),
        ];
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function resolveAll(InstalledPlugin $plugin, PluginId $id, array $paths): array
    {
        $urls = [];
        foreach ($paths as $path) {
            $url = $this->resolveOne($plugin, $id, $path);
            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function resolveOne(InstalledPlugin $plugin, PluginId $id, string $path): ?string
    {
        $absolutePath = $this->assets->resolveContainedFile($plugin, $path);
        if ($absolutePath === null) {
            $this->logger->warning('Declared UI asset was skipped: file was not found under the plugin\'s assets directory.', [
                'pluginId' => (string) $id,
                'path' => $path,
            ]);

            return null;
        }

        if (!PluginAssetResolver::isServableExtension($path)) {
            $this->logger->warning('Declared UI asset was skipped: file extension is not servable by the plugin asset route.', [
                'pluginId' => (string) $id,
                'path' => $path,
            ]);

            return null;
        }

        return $this->urlGenerator->generate('plugin_asset', [
            'pluginId' => (string) $id,
            'fingerprint' => PluginAssetFingerprint::forFile($absolutePath),
            'path' => $path,
        ]);
    }
}
