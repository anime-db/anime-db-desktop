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

namespace App\Tests\Unit\Service\Download;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Download;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Repository\DownloadRepository;
use App\Service\Download\DownloadsOverviewBuilder;
use App\Service\Storage\StorageMarkerService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * Pins the exact status-text rules from issue #854's "Что делается" §3 — each one independently,
 * since getting one branch's precedence wrong (e.g. checking progress before the storage marker)
 * would silently change another scenario's text without any single test catching both.
 *
 * Loads the real app/translations/messages.ru.yaml catalog rather than stubbing the translator:
 * a typo'd or missing key would still "pass" against a stub that just echoes keys back, defeating
 * the point of pinning the rendered text.
 */
final class DownloadsOverviewBuilderTest extends TestCase
{
    private const string ROOT = 'C:\\Users\\bob\\Downloads';

    private EntityManager $entityManager;
    private DownloadRepository $downloads;
    private DownloadsOverviewBuilder $builder;
    /** @var list<string> */
    private array $dirsToClean = [];

    protected function setUp(): void
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 4).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->downloads = new DownloadRepository($this->entityManager);

        $translator = new Translator('ru');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', \dirname(__DIR__, 4).'/translations/messages.ru.yaml', 'ru');

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route, array $params = []): string => \sprintf('/anime/%d', $params['id']),
        );

        $this->builder = new DownloadsOverviewBuilder(
            $this->downloads,
            new StorageMarkerService($this->entityManager),
            $translator,
            $urlGenerator,
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->dirsToClean as $dir) {
            $marker = $dir.\DIRECTORY_SEPARATOR.'desktop.ini';
            if (is_file($marker)) {
                unlink($marker);
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    private function makeDir(): string
    {
        $dir = sys_get_temp_dir().'/downloads-overview-test-'.uniqid();
        mkdir($dir, recursive: true);
        $this->dirsToClean[] = $dir;

        return $dir;
    }

    private function persistStorage(?string $path = null): Storage
    {
        $storage = new Storage('Main folder', $path ?? self::ROOT, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        return $storage;
    }

    private function persistAnime(string $title = 'Trigun'): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle($title)->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    private function persistDownload(string $infoHash, TvAnime $anime, ?Storage $targetStorage = null): Download
    {
        $download = new Download($infoHash, $anime);
        if ($targetStorage !== null) {
            $download->assignTargetStorage($targetStorage);
        }
        $this->downloads->save($download);

        return $download;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function torrent(string $infoHashV1, array $overrides = []): array
    {
        return array_merge([
            'infohash_v1' => $infoHashV1,
            'name' => 'Some torrent',
            'size' => 1024 * 1024 * 700,
            'progress' => 0.4,
            'dlspeed' => 1024 * 50,
            'upspeed' => 0,
            'eta' => 120,
            'state' => 'downloading',
            'num_seeds' => 3,
            'num_leechs' => 1,
        ], $overrides);
    }

    public function testPendingRowWithProgressShowsWaitingAndLiveFields(): void
    {
        $anime = $this->persistAnime();
        $this->persistDownload(str_repeat('a', 40), $anime);

        $result = $this->builder->build([$this->torrent(str_repeat('a', 40), ['progress' => 0.4])], true);

        self::assertCount(1, $result['rows']);
        $row = $result['rows'][0];
        self::assertSame('Ждёт', $row['statusText']);
        self::assertSame('40%', $row['progressText']);
        self::assertNotNull($row['sizeText']);
    }

    public function testPendingAtFullProgressShowsLinking(): void
    {
        $anime = $this->persistAnime();
        $this->persistDownload(str_repeat('b', 40), $anime);

        $result = $this->builder->build([$this->torrent(str_repeat('b', 40), ['progress' => 1.0])], true);

        self::assertSame('Докачано, привязывается…', $result['rows'][0]['statusText']);
    }

    /**
     * After issue #851, every real Pending row has a targetStorage, so this (marker matches, not
     * the mismatch case below) is the actual common path in production — without it, a regression
     * that always reports "storage unavailable" would pass every other test in this class, since
     * only testPendingWithMismatchedStorageMarkerShowsStorageUnavailableInsteadOfFailed() below
     * sets a targetStorage at all, and it expects exactly that text anyway.
     */
    public function testPendingWithMatchingStorageMarkerShowsWaitingAtLowProgress(): void
    {
        $dir = $this->makeDir();
        $storage = $this->persistStorage($dir);
        file_put_contents($dir.\DIRECTORY_SEPARATOR.'desktop.ini', "[AnimeDB]\nid=".$storage->id."\n");

        $anime = $this->persistAnime();
        $this->persistDownload(str_repeat('5', 40), $anime, $storage);

        $result = $this->builder->build([$this->torrent(str_repeat('5', 40), ['progress' => 0.4])], true);

        self::assertSame('Ждёт', $result['rows'][0]['statusText']);
    }

    public function testPendingWithMatchingStorageMarkerShowsLinkingAtFullProgress(): void
    {
        $dir = $this->makeDir();
        $storage = $this->persistStorage($dir);
        file_put_contents($dir.\DIRECTORY_SEPARATOR.'desktop.ini', "[AnimeDB]\nid=".$storage->id."\n");

        $anime = $this->persistAnime();
        $this->persistDownload(str_repeat('6', 40), $anime, $storage);

        $result = $this->builder->build([$this->torrent(str_repeat('6', 40), ['progress' => 1.0])], true);

        self::assertSame('Докачано, привязывается…', $result['rows'][0]['statusText']);
    }

    public function testPendingWithMismatchedStorageMarkerShowsStorageUnavailableInsteadOfFailed(): void
    {
        $dir = $this->makeDir();
        $storage = $this->persistStorage($dir);
        // A marker naming a DIFFERENT storage id than $storage's own — the "drive reconnected
        // under someone else's marker" / "port moved" scenario from issue #854.
        file_put_contents($dir.\DIRECTORY_SEPARATOR.'desktop.ini', "[AnimeDB]\nid=".($storage->id + 1)."\n");

        $anime = $this->persistAnime();
        $this->persistDownload(str_repeat('c', 40), $anime, $storage);

        // Even at full progress, the storage check wins — it is not safe to claim "finished,
        // linking" into a destination that is not reachable.
        $result = $this->builder->build([$this->torrent(str_repeat('c', 40), ['progress' => 1.0])], true);

        self::assertSame('Хранилище недоступно', $result['rows'][0]['statusText']);
    }

    public function testCompletedRowWithoutTorrentInClientShowsSeedingStoppedNotAnError(): void
    {
        $anime = $this->persistAnime();
        $download = $this->persistDownload(str_repeat('d', 40), $anime);
        $download->markCompleted();
        $this->entityManager->flush();

        $result = $this->builder->build([], true);

        self::assertSame('Раздача остановлена', $result['rows'][0]['statusText']);
        self::assertSame('completed', $result['rows'][0]['coreStatus']);
    }

    public function testCompletedRowWithTorrentStillInClientShowsDoneAndLinked(): void
    {
        $anime = $this->persistAnime();
        $download = $this->persistDownload(str_repeat('e', 40), $anime);
        $download->markCompleted();
        $this->entityManager->flush();

        $result = $this->builder->build([$this->torrent(str_repeat('e', 40))], true);

        self::assertSame('Готово и привязано', $result['rows'][0]['statusText']);
    }

    public function testFailedRowWithTorrentPresentShowsReasonText(): void
    {
        $anime = $this->persistAnime();
        $download = $this->persistDownload(str_repeat('f', 40), $anime);
        $download->markFailed();
        (new \ReflectionProperty(Download::class, 'failureReason'))->setValue($download, 'disk_space');
        $this->entityManager->flush();

        $result = $this->builder->build([$this->torrent(str_repeat('f', 40))], true);

        self::assertSame('Ошибка: не хватает места на диске.', $result['rows'][0]['statusText']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function missingFromClientStatusProvider(): iterable
    {
        yield 'Pending' => ['pending'];
        yield 'Failed' => ['failed'];
    }

    /**
     * @param 'pending'|'failed' $status
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('missingFromClientStatusProvider')]
    public function testPendingOrFailedWithoutTorrentInClientShowsMissingFromClient(string $status): void
    {
        $anime = $this->persistAnime();
        $download = $this->persistDownload(str_repeat('1', 40), $anime);
        if ($status === 'failed') {
            $download->markFailed();
            $this->entityManager->flush();
        }

        // qBittorrent WAS reachable (true) but this infoHash is simply not among its torrents.
        $result = $this->builder->build([], true);

        self::assertSame('Нет в торрент-клиенте', $result['rows'][0]['statusText']);
    }

    public function testOrphanTorrentWithoutADownloadRowIsListedSeparatelyAsNoCard(): void
    {
        $result = $this->builder->build([$this->torrent(str_repeat('2', 40), ['name' => 'Mystery torrent'])], true);

        self::assertSame([], $result['rows']);
        self::assertCount(1, $result['orphans']);
        $orphan = $result['orphans'][0];
        self::assertFalse($orphan['hasCard']);
        self::assertSame('Mystery torrent', $orphan['displayName']);
        self::assertSame('Без карточки', $orphan['statusText']);
    }

    public function testQbittorrentUnavailableDegradesToDbOnlyFieldsAndNoOrphans(): void
    {
        $anime = $this->persistAnime();
        $pending = $this->persistDownload(str_repeat('3', 40), $anime);
        $completedDownload = $this->persistDownload(str_repeat('4', 40), $anime);
        $completedDownload->markCompleted();
        $this->entityManager->flush();

        $result = $this->builder->build([], false);

        self::assertCount(2, $result['rows']);
        self::assertSame([], $result['orphans'], 'Unknown whether any orphan torrents exist while qBittorrent is unreachable.');

        $byHash = [];
        foreach ($result['rows'] as $row) {
            $byHash[$row['infoHash']] = $row;
        }

        self::assertSame('Ждёт', $byHash[$pending->getInfoHash()]['statusText']);
        self::assertNull($byHash[$pending->getInfoHash()]['sizeText']);
        self::assertSame('Готово и привязано', $byHash[$completedDownload->getInfoHash()]['statusText']);
    }
}
