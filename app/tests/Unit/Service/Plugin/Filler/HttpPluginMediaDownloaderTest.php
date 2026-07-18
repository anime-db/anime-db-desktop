<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Plugin\Filler;

use App\Service\Plugin\Filler\HttpPluginMediaDownloader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpPluginMediaDownloaderTest extends TestCase
{
    private string $mediaDir;

    protected function setUp(): void
    {
        $this->mediaDir = sys_get_temp_dir().'/http-plugin-media-downloader-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->mediaDir)) {
            $this->removeDir($this->mediaDir);
        }
    }

    public function testDownloadSavesFileFromPublicHost(): void
    {
        $httpClient = new MockHttpClient([new MockResponse('binary-content')]);
        $downloader = new HttpPluginMediaDownloader($httpClient, $this->mediaDir);

        $filename = $downloader->download(1, 'https://8.8.8.8/cover.jpg');

        self::assertNotNull($filename);
        self::assertSame('binary-content', file_get_contents($this->mediaDir.'/1/'.$filename));
    }

    public function testDownloadRejectsLoopbackHost(): void
    {
        $httpClient = new MockHttpClient([new MockResponse('binary-content')]);
        $downloader = new HttpPluginMediaDownloader($httpClient, $this->mediaDir);

        $filename = $downloader->download(1, 'http://127.0.0.1/cover.jpg');

        self::assertNull($filename);
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testDownloadRejectsLinkLocalHost(): void
    {
        $httpClient = new MockHttpClient([new MockResponse('binary-content')]);
        $downloader = new HttpPluginMediaDownloader($httpClient, $this->mediaDir);

        // 169.254.169.254 is the cloud-metadata address abused by real-world SSRF exploits.
        $filename = $downloader->download(1, 'http://169.254.169.254/cover.jpg');

        self::assertNull($filename);
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testDownloadRejectsNonHttpScheme(): void
    {
        $httpClient = new MockHttpClient([new MockResponse('binary-content')]);
        $downloader = new HttpPluginMediaDownloader($httpClient, $this->mediaDir);

        $filename = $downloader->download(1, 'file:///etc/passwd');

        self::assertNull($filename);
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testDownloadRejectsRedirectToPrivateHost(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'http://169.254.169.254/secret.jpg']]),
        ]);
        $downloader = new HttpPluginMediaDownloader($httpClient, $this->mediaDir);

        $filename = $downloader->download(1, 'https://8.8.8.8/redirect.jpg');

        self::assertNull($filename);
    }

    public function testDownloadSkipsNetworkWhenFileAlreadyExists(): void
    {
        $httpClient = new MockHttpClient([]);
        $downloader = new HttpPluginMediaDownloader($httpClient, $this->mediaDir);

        $existingDir = $this->mediaDir.'/1';
        mkdir($existingDir, 0o755, true);
        $existingFilename = sha1('https://8.8.8.8/cover.jpg').'.jpg';
        file_put_contents($existingDir.'/'.$existingFilename, 'already-downloaded');

        $filename = $downloader->download(1, 'https://8.8.8.8/cover.jpg');

        self::assertSame($existingFilename, $filename);
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testDownloadCreatesDirectoryWithoutWorldWritePermission(): void
    {
        $httpClient = new MockHttpClient([new MockResponse('binary-content')]);
        $downloader = new HttpPluginMediaDownloader($httpClient, $this->mediaDir);

        $previousUmask = umask(0);
        try {
            $downloader->download(1, 'https://8.8.8.8/cover.jpg');
        } finally {
            umask($previousUmask);
        }

        self::assertSame('0755', substr(sprintf('%o', fileperms($this->mediaDir.'/1')), -4));
    }

    private function removeDir(string $dir): void
    {
        $items = scandir($dir);
        if (false === $items) {
            return;
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
