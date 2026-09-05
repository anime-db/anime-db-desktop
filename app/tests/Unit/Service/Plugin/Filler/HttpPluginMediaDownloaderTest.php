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

namespace App\Tests\Unit\Service\Plugin\Filler;

use App\Service\Media\ImageNormalizer;
use App\Service\Plugin\Filler\HostResolverInterface;
use App\Service\Plugin\Filler\HttpPluginMediaDownloader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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

    public function testDownloadNormalizesAndSavesFileFromPublicHost(): void
    {
        $httpClient = new MockHttpClient([new MockResponse($this->createPngBytes())]);
        $downloader = $this->createDownloader($httpClient);

        $filename = $downloader->download(1, 'https://8.8.8.8/cover.png');

        self::assertSame(sha1('https://8.8.8.8/cover.png').'.webp', $filename);
        $bytes = file_get_contents($this->mediaDir.'/1/'.$filename);
        self::assertNotFalse($bytes);
        self::assertStringStartsWith('RIFF', $bytes);
        self::assertSame('WEBP', substr($bytes, 8, 4));
    }

    public function testDownloadReturnsNullAndSavesNothingWhenNormalizerRejectsTheBody(): void
    {
        $httpClient = new MockHttpClient([new MockResponse('not-an-image')]);
        $downloader = $this->createDownloader($httpClient);

        $filename = $downloader->download(1, 'https://8.8.8.8/cover.png');

        self::assertNull($filename);
        self::assertFalse(is_file($this->mediaDir.'/1/'.sha1('https://8.8.8.8/cover.png').'.webp'));
        self::assertFalse(is_dir($this->mediaDir.'/1'));
    }

    public function testDownloadRejectsLoopbackHost(): void
    {
        $httpClient = new MockHttpClient([new MockResponse($this->createPngBytes())]);
        $downloader = $this->createDownloader($httpClient);

        $filename = $downloader->download(1, 'http://127.0.0.1/cover.jpg');

        self::assertNull($filename);
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testDownloadRejectsLinkLocalHost(): void
    {
        $httpClient = new MockHttpClient([new MockResponse($this->createPngBytes())]);
        $downloader = $this->createDownloader($httpClient);

        // 169.254.169.254 is the cloud-metadata address abused by real-world SSRF exploits.
        $filename = $downloader->download(1, 'http://169.254.169.254/cover.jpg');

        self::assertNull($filename);
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testDownloadRejectsNonHttpScheme(): void
    {
        $httpClient = new MockHttpClient([new MockResponse($this->createPngBytes())]);
        $downloader = $this->createDownloader($httpClient);

        $filename = $downloader->download(1, 'file:///etc/passwd');

        self::assertNull($filename);
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testDownloadRejectsRedirectToPrivateHost(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'http://169.254.169.254/secret.jpg']]),
        ]);
        $downloader = $this->createDownloader($httpClient);

        $filename = $downloader->download(1, 'https://8.8.8.8/redirect.jpg');

        self::assertNull($filename);
    }

    public function testDownloadRejectsCgnatHost(): void
    {
        $httpClient = new MockHttpClient([new MockResponse($this->createPngBytes())]);
        $downloader = $this->createDownloader($httpClient);

        // 100.64.0.0/10 (RFC 6598 carrier-grade NAT) is not covered by FILTER_FLAG_NO_PRIV_RANGE.
        $filename = $downloader->download(1, 'http://100.64.0.1/cover.jpg');

        self::assertNull($filename);
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testDownloadRejectsHostThatResolvesToLoopbackOnALaterRequest(): void
    {
        // Simulates a short-TTL DNS record: the first lookup answers with a public address (so
        // the check passes), a later lookup for the same host answers with a loopback address.
        // Fixing the checked address into the request (instead of letting the client resolve the
        // host again on its own) is what has to stop the second, unsafe answer from being used —
        // this test is red without that fix.
        $resolver = new FakeHostResolver([['8.8.8.8'], ['127.0.0.1']]);
        $httpClient = new MockHttpClient([new MockResponse($this->createPngBytes())]);
        $downloader = $this->createDownloader($httpClient, $resolver);

        $first = $downloader->download(1, 'https://rebinding.example.test/first.jpg');
        $second = $downloader->download(1, 'https://rebinding.example.test/second.jpg');

        self::assertNotNull($first);
        self::assertNull($second);
    }

    public function testDownloadPinsTheRequestToTheValidatedAddress(): void
    {
        $resolver = new FakeHostResolver([['8.8.8.8']]);
        $capturedOptions = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
            $capturedOptions = $options;

            return new MockResponse($this->createPngBytes());
        });
        $downloader = $this->createDownloader($httpClient, $resolver);

        $filename = $downloader->download(1, 'https://public.example.test/cover.jpg');

        self::assertNotNull($filename);
        self::assertSame(['public.example.test' => '8.8.8.8'], $capturedOptions['resolve'] ?? null);
    }

    public function testDownloadPinsTheResolvedAddressOnEachRedirectHop(): void
    {
        $resolver = new FakeHostResolver([['8.8.8.8'], ['9.9.9.9']]);
        $capturedOptions = [];
        $httpClient = new MockHttpClient([
            function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
                $capturedOptions[] = $options;

                return new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://second.example.test/cover.jpg']]);
            },
            function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
                $capturedOptions[] = $options;

                return new MockResponse($this->createPngBytes());
            },
        ]);
        $downloader = $this->createDownloader($httpClient, $resolver);

        $filename = $downloader->download(1, 'https://first.example.test/cover.jpg');

        self::assertNotNull($filename);
        self::assertSame(['first.example.test' => '8.8.8.8'], $capturedOptions[0]['resolve'] ?? null);
        self::assertSame(['second.example.test' => '9.9.9.9'], $capturedOptions[1]['resolve'] ?? null);
    }

    public function testDownloadSkipsNetworkWhenFileAlreadyExists(): void
    {
        $httpClient = new MockHttpClient([]);
        $downloader = $this->createDownloader($httpClient);

        $existingDir = $this->mediaDir.'/1';
        mkdir($existingDir, 0o755, true);
        $existingFilename = sha1('https://8.8.8.8/cover.jpg').'.webp';
        file_put_contents($existingDir.'/'.$existingFilename, 'already-downloaded');

        $filename = $downloader->download(1, 'https://8.8.8.8/cover.jpg');

        self::assertSame($existingFilename, $filename);
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testDownloadCreatesDirectoryWithoutWorldWritePermission(): void
    {
        $httpClient = new MockHttpClient([new MockResponse($this->createPngBytes())]);
        $downloader = $this->createDownloader($httpClient);

        $previousUmask = umask(0);
        try {
            $downloader->download(1, 'https://8.8.8.8/cover.jpg');
        } finally {
            umask($previousUmask);
        }

        self::assertSame('0755', substr(sprintf('%o', fileperms($this->mediaDir.'/1')), -4));
    }

    public function testDownloadLeavesNoTemporaryFileBehindAfterASuccessfulWrite(): void
    {
        $httpClient = new MockHttpClient([new MockResponse($this->createPngBytes())]);
        $downloader = $this->createDownloader($httpClient);

        $filename = $downloader->download(1, 'https://8.8.8.8/cover.png');

        self::assertNotNull($filename);
        $entries = array_values(array_diff(scandir($this->mediaDir.'/1') ?: [], ['.', '..']));
        self::assertSame([$filename], $entries);
    }

    private function createDownloader(MockHttpClient $httpClient, ?HostResolverInterface $hostResolver = null): HttpPluginMediaDownloader
    {
        return new HttpPluginMediaDownloader(
            $httpClient,
            new ImageNormalizer(),
            new NullLogger(),
            $hostResolver ?? new FakeHostResolver([]),
            $this->mediaDir,
        );
    }

    private function createPngBytes(): string
    {
        $image = imagecreatetruecolor(2, 2);
        self::assertNotFalse($image);

        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        if ($bytes === false) {
            throw new \RuntimeException('ob_get_clean() unexpectedly returned false.');
        }

        return $bytes;
    }

    private function removeDir(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
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

/** Returns one queued answer per {@see resolve()} call, in order, so a test can simulate a host's DNS record changing between requests. */
final class FakeHostResolver implements HostResolverInterface
{
    /**
     * @param list<list<string>> $answers
     */
    public function __construct(private array $answers)
    {
    }

    public function resolve(string $host): array
    {
        return array_shift($this->answers) ?? [];
    }
}
