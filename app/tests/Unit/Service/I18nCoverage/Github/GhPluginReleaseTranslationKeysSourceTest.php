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

namespace App\Tests\Unit\Service\I18nCoverage\Github;

use App\Service\I18nCoverage\Github\GhPluginReleaseTranslationKeysSource;
use App\Tests\Unit\Service\I18nCoverage\Github\Fake\FakeGhReleaseZipDownloader;
use PHPUnit\Framework\TestCase;

/**
 * No network, no `gh`, no `git`: the downloader seam ({@see \App\Service\I18nCoverage\Github\GhReleaseZipDownloader})
 * is faked with a real zip file built on local disk, so this exercises the actual zip-reading
 * code path end to end.
 */
final class GhPluginReleaseTranslationKeysSourceTest extends TestCase
{
    public function testKeysComeFromTheReleaseAssetAndTheRequestedPluginId(): void
    {
        $releaseZip = $this->buildZip(['translations/messages.de.yaml' => "welcome: Hallo\ngoodbye: Tschuss\n"]);
        $downloader = new FakeGhReleaseZipDownloader($releaseZip);

        $source = new GhPluginReleaseTranslationKeysSource('animedb-language-pack', $downloader);

        self::assertSame(['goodbye', 'welcome'], $source->keys());
        // Asserts the seam itself: the class must ask the downloader for *this* plugin's latest
        // release, not read a working-tree checkout or some other version — swapping the source
        // for one that reads a working tree, or hardcoding a different plugin id, fails this.
        self::assertSame('animedb-language-pack', $downloader->requestedPluginId);
    }

    public function testKeysAreTheIntersectionAcrossEveryShippedLocale(): void
    {
        $zip = $this->buildZip([
            'translations/messages.de.yaml' => "welcome: Hallo\ngoodbye: Tschuss\n",
            'translations/messages.ja.yaml' => "welcome: Konnichiwa\n",
        ]);

        $source = new GhPluginReleaseTranslationKeysSource('animedb-language-pack', new FakeGhReleaseZipDownloader($zip));

        // "goodbye" is missing from the ja catalog, so the plugin as a whole does not yet cover it.
        self::assertSame(['welcome'], $source->keys());
    }

    /**
     * The reader only globs `translations/messages.*.yaml`; native-i18n JSON catalogs
     * ({@see \App\Service\I18nCoverage\AppReferenceTranslationKeysSource}'s counterpart for
     * `native/`) live under a different path and format and must not contribute keys. If that
     * glob is ever widened to also pick up `translations/native/*.json`, a plugin shipping keys
     * there but not in the YAML catalog would silently start looking orphaned/missing for keys it
     * never claimed through this source.
     */
    public function testNativeJsonCatalogsAreIgnoredAndOnlyYamlKeysAreReturned(): void
    {
        $zip = $this->buildZip([
            'translations/messages.de.yaml' => "welcome: Hallo\n",
            'translations/native/de.json' => '{"tray.quit": "Beenden"}',
        ]);

        $source = new GhPluginReleaseTranslationKeysSource('animedb-language-pack', new FakeGhReleaseZipDownloader($zip));

        self::assertSame(['welcome'], $source->keys());
    }

    public function testEmptyKeySetWhenTheArchiveHasNoLocaleCatalog(): void
    {
        $zip = $this->buildZip(['manifest.json' => '{"id":"animedb-language-pack"}']);

        $source = new GhPluginReleaseTranslationKeysSource('animedb-language-pack', new FakeGhReleaseZipDownloader($zip));

        self::assertSame([], $source->keys());
    }

    /**
     * A release asset packaged by zipping a directory directly (e.g. `zip -r plugin.zip plugin/`)
     * commonly wraps every entry in one top-level directory instead of putting `translations/` at
     * the archive root — the same layout `ZipPluginInstaller::resolvePluginRoot()` already handles
     * for `manifest.json`. The reader must still find the catalogs in that case, not silently
     * return an empty key set.
     */
    public function testKeysAreFoundWhenTheArchiveWrapsEverythingInATopLevelDirectory(): void
    {
        $zip = $this->buildZip([
            'animedb-language-pack/translations/messages.de.yaml' => "welcome: Hallo\ngoodbye: Tschuss\n",
        ]);

        $source = new GhPluginReleaseTranslationKeysSource('animedb-language-pack', new FakeGhReleaseZipDownloader($zip));

        self::assertSame(['goodbye', 'welcome'], $source->keys());
    }

    /**
     * @param array<string, string> $files relative path => content
     */
    private function buildZip(array $files): string
    {
        $zipPath = sys_get_temp_dir().'/anime-i18n-coverage-release-'.uniqid().'.zip';

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        foreach ($files as $relativePath => $content) {
            $zip->addFromString($relativePath, $content);
        }
        $zip->close();

        return $zipPath;
    }
}
