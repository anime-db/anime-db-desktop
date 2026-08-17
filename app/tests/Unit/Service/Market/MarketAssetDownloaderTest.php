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

namespace App\Tests\Unit\Service\Market;

use App\Entity\ValueObject\PluginId;
use App\Service\Market\Exception\PluginAssetDownloadException;
use App\Service\Market\Exception\UnknownPluginVersionException;
use App\Service\Market\MarketAssetDownloader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MarketAssetDownloaderTest extends TestCase
{
    private const string PLUGIN_ZIP_CONTENT = 'trusted plugin archive bytes';

    public function testDerivesTheAssetUrlFromAssetMirrorsAndDownloadsTheVerifiedArchive(): void
    {
        $requestedUrls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$requestedUrls): MockResponse {
            $requestedUrls[] = $url;

            return new MockResponse(self::PLUGIN_ZIP_CONTENT);
        }, null);

        $downloader = new MarketAssetDownloader($httpClient);
        $localPath = $downloader->downloadPluginZip(
            hash('sha256', self::PLUGIN_ZIP_CONTENT),
            ['https://github.com/anime-db/anime-db-plugins/releases/download/<id>/<version>/<file>'],
            new PluginId('animedb-shikimori'),
            '1.2.0',
        );

        try {
            $this->assertSame([
                'https://github.com/anime-db/anime-db-plugins/releases/download/animedb-shikimori/1.2.0/plugin.zip',
            ], $requestedUrls);
            $this->assertSame(self::PLUGIN_ZIP_CONTENT, file_get_contents($localPath));
        } finally {
            unlink($localPath);
        }
    }

    public function testFallsBackToTheNextMirrorWhenTheFirstServesAChecksumMismatch(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            return str_contains($url, 'mirror-a') ? new MockResponse('corrupted bytes') : new MockResponse(self::PLUGIN_ZIP_CONTENT);
        }, null);

        $downloader = new MarketAssetDownloader($httpClient);
        $localPath = $downloader->downloadPluginZip(
            hash('sha256', self::PLUGIN_ZIP_CONTENT),
            [
                'https://mirror-a.example/<id>/<version>/<file>',
                'https://mirror-b.example/<id>/<version>/<file>',
            ],
            new PluginId('animedb-shikimori'),
            '1.2.0',
        );

        try {
            $this->assertSame(self::PLUGIN_ZIP_CONTENT, file_get_contents($localPath));
        } finally {
            unlink($localPath);
        }
    }

    public function testRejectsInstallationWhenEveryMirrorFailsOrMismatches(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, 'mirror-a')) {
                return new MockResponse('corrupted bytes');
            }

            throw new TransportException('Connection refused.');
        }, null);

        $downloader = new MarketAssetDownloader($httpClient);

        $this->expectException(PluginAssetDownloadException::class);

        $downloader->downloadPluginZip(
            hash('sha256', self::PLUGIN_ZIP_CONTENT),
            [
                'https://mirror-a.example/<id>/<version>/<file>',
                'https://mirror-b.example/<id>/<version>/<file>',
            ],
            new PluginId('animedb-shikimori'),
            '1.2.0',
        );
    }

    public function testThrowsWhenNoChecksumIsPinnedForThePluginVersion(): void
    {
        $downloader = new MarketAssetDownloader(new MockHttpClient());

        $this->expectException(UnknownPluginVersionException::class);

        $downloader->downloadPluginZip(null, ['https://mirror-a.example/<id>/<version>/<file>'], new PluginId('animedb-shikimori'), '9.9.9');
    }
}
