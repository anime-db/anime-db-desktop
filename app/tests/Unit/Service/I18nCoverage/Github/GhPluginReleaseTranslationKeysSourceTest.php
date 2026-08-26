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
    public function testKeysComeFromTheReleaseAssetNotAWorkingTreeCheckout(): void
    {
        // What the plugin's latest *released* plugin.zip actually contains.
        $releaseZip = $this->buildZip(['translations/messages.de.yaml' => "welcome: Hallo\ngoodbye: Tschuss\n"]);

        // What a monorepo working-tree checkout of plugins/<id>/ would contain right now — ahead
        // of the release, and never wired into the class under test at all.
        $this->buildWorkingTreeDir(['translations/messages.de.yaml' => "welcome: Hallo\ngoodbye: Tschuss\nunreleased: Nur im Master\n"]);

        $source = new GhPluginReleaseTranslationKeysSource('animedb-language-pack', new FakeGhReleaseZipDownloader($releaseZip));

        self::assertSame(['goodbye', 'welcome'], $source->keys());
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

    public function testEmptyKeySetWhenTheArchiveHasNoLocaleCatalog(): void
    {
        $zip = $this->buildZip(['manifest.json' => '{"id":"animedb-language-pack"}']);

        $source = new GhPluginReleaseTranslationKeysSource('animedb-language-pack', new FakeGhReleaseZipDownloader($zip));

        self::assertSame([], $source->keys());
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

    /**
     * @param array<string, string> $files relative path => content
     */
    private function buildWorkingTreeDir(array $files): string
    {
        $dir = sys_get_temp_dir().'/anime-i18n-coverage-master-'.uniqid();
        foreach ($files as $relativePath => $content) {
            $fullPath = $dir.'/'.$relativePath;
            mkdir(\dirname($fullPath), recursive: true);
            file_put_contents($fullPath, $content);
        }

        return $dir;
    }
}
