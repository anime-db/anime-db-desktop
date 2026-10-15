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

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\EventListener\AnimeSourceNormalizedUrlListener;
use App\Message\IndexAnimeMessage;
use App\Message\RecomputeSourceUrlsMessage;
use App\MessageHandler\RecomputeSourceUrlsMessageHandler;
use App\Service\Catalog\SourceUrlNormalizer;
use App\Service\Catalog\SourceUrlsFingerprintStore;
use App\Service\Catalog\SourceUrlsRecomputeScheduler;
use App\Service\Plugin\LinkRulesProvider;
use App\Tests\Support\BuildsLinkRulesPlugins;
use App\Tests\Support\CreatesInMemoryEntityManager;
use App\Tests\Support\TemporaryDirectories;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RecomputeSourceUrlsMessageHandlerTest extends TestCase
{
    use BuildsLinkRulesPlugins;
    use CreatesInMemoryEntityManager;
    use TemporaryDirectories;

    private EntityManager $entityManager;
    private Connection $connection;

    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->entityManager = $this->createInMemoryEntityManager();
        $this->connection = $this->entityManager->getConnection();
        $this->connection->executeStatement('CREATE TABLE app_meta ("key" VARCHAR(64) NOT NULL, value CLOB NOT NULL, PRIMARY KEY ("key"))');
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectories();
    }

    public function testRecomputeAppliesRulesAndWritesTheFingerprintAtTheEnd(): void
    {
        $this->writeLinkRulesPlugin('acme-rules', ['^https://old\.example/(\d+)$' => 'https://new.example/$1']);
        $provider = new LinkRulesProvider($this->linkRulesRegistry());
        $this->addAnime('https://old.example/1', 'https://other.example/x');

        $this->handler($provider)(new RecomputeSourceUrlsMessage());

        $this->assertSame(
            ['https://new.example/1', 'https://other.example/x'],
            $this->connection->fetchFirstColumn('SELECT normalized_url FROM anime_source ORDER BY id'),
        );
        $this->assertSame(['https://old.example/1', 'https://other.example/x'], $this->connection->fetchFirstColumn('SELECT url FROM anime_source ORDER BY id'));
        $this->assertSame($provider->fingerprint(), $this->store()->get());
        $this->assertSame([], $this->dispatched);
    }

    public function testRecomputeDoesNotTouchDateUpdateNorQueueIndexing(): void
    {
        $this->writeLinkRulesPlugin('acme-rules', ['^https://old\.example/(\d+)$' => 'https://new.example/$1']);
        $this->addAnime('https://old.example/1');
        $this->addAnime('https://old.example/2');
        $this->connection->executeStatement('UPDATE anime SET date_update = 1000');

        $this->handler(new LinkRulesProvider($this->linkRulesRegistry()))(new RecomputeSourceUrlsMessage());

        $this->assertSame(['1000', '1000'], array_map('strval', $this->connection->fetchFirstColumn('SELECT date_update FROM anime')));
        $this->assertSame(['https://new.example/1', 'https://new.example/2'], $this->connection->fetchFirstColumn('SELECT normalized_url FROM anime_source ORDER BY id'));
        $this->assertSame([], array_filter($this->dispatched, static fn (object $message): bool => $message instanceof IndexAnimeMessage));
    }

    public function testSecondRunChangesNothing(): void
    {
        $this->writeLinkRulesPlugin('acme-rules', ['^https://old\.example/(\d+)$' => 'https://new.example/$1']);
        $provider = new LinkRulesProvider($this->linkRulesRegistry());
        $this->addAnime('https://old.example/1');
        $handler = $this->handler($provider);

        $handler(new RecomputeSourceUrlsMessage());
        $before = $this->connection->fetchAllAssociative('SELECT * FROM anime_source');
        $handler(new RecomputeSourceUrlsMessage());

        $this->assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM anime_source'));
        $this->assertSame($provider->fingerprint(), $this->store()->get());
        $this->assertSame([], $this->dispatched);
    }

    public function testRulesAreSnapshotOncePerRecompute(): void
    {
        $this->writeLinkRulesPlugin('acme-rules', ['^https://old\.example/(\d+)$' => 'https://new.example/$1']);
        $provider = new LinkRulesProvider($this->linkRulesRegistry());
        $this->addAnime(...array_map(static fn (int $i): string => 'https://old.example/'.$i, range(1, 30)));
        // The rule file disappears right after the first row is recomputed: rows after it are
        // still computed by the snapshot, not by a re-read.
        $this->runOnFirstRowUpdate(fn () => unlink($this->linkRulesPluginsDir().'/acme-rules/link-rules.yaml'));

        $this->handler($provider)(new RecomputeSourceUrlsMessage());

        $this->assertSame(
            array_map(static fn (int $i): string => 'https://new.example/'.$i, range(1, 30)),
            $this->connection->fetchFirstColumn('SELECT normalized_url FROM anime_source ORDER BY id'),
        );
    }

    public function testFingerprintIsNotWrittenAndRecomputeIsQueuedAgainWhenTheRulesChangedMeanwhile(): void
    {
        $this->writeLinkRulesPlugin('acme-rules', ['^https://old\.example/(\d+)$' => 'https://new.example/$1']);
        $provider = new LinkRulesProvider($this->linkRulesRegistry());
        $this->addAnime('https://old.example/1');
        $snapshotFingerprint = $provider->fingerprint();

        // A plugin appears while the rows are being recomputed.
        $this->runOnFirstRowUpdate(function (): void {
            $this->writeLinkRulesPlugin('acme-more', ['^https://x\.example/(\d+)$' => 'https://y.example/$1']);
            $this->linkRulesRegistry();
        });
        $this->handler($provider)(new RecomputeSourceUrlsMessage());

        $this->assertNull($this->store()->get());
        $this->assertNotSame($snapshotFingerprint, $provider->fingerprint());
        $this->assertCount(1, $this->dispatched);
        $this->assertInstanceOf(RecomputeSourceUrlsMessage::class, $this->dispatched[0]);
    }

    public function testNewSourceGetsTheRuleAppliedOnPersist(): void
    {
        $this->writeLinkRulesPlugin('acme-rules', ['^https://old\.example/(\d+)$' => 'https://new.example/$1']);
        $listener = new AnimeSourceNormalizedUrlListener(new SourceUrlNormalizer(new LinkRulesProvider($this->linkRulesRegistry())));
        $this->entityManager->getEventManager()->addEventListener('prePersist', $listener);

        $anime = (new MovieAnime())->setTitle('Title')->setWatchStatus(WatchStatus::Watching);
        $anime->addSource('https://old.example/7');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->assertSame(['https://new.example/7'], $this->connection->fetchFirstColumn('SELECT normalized_url FROM anime_source'));
    }

    /**
     * Runs $hook in the middle of the recompute: from a trigger on the first `UPDATE anime_source`.
     */
    private function runOnFirstRowUpdate(callable $hook): void
    {
        $done = false;
        $native = $this->connection->getNativeConnection();
        \assert($native instanceof \PDO);
        $native->sqliteCreateFunction('test_hook', static function () use ($hook, &$done): int {
            if (!$done) {
                $done = true;
                $hook();
            }

            return 1;
        });
        $this->connection->executeStatement('CREATE TRIGGER test_hook AFTER UPDATE ON anime_source BEGIN SELECT test_hook(); END');
    }

    private function addAnime(string ...$urls): void
    {
        $anime = (new MovieAnime())->setTitle('Title')->setWatchStatus(WatchStatus::Watching);
        foreach ($urls as $url) {
            $anime->addSource($url);
        }
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $this->connection->executeStatement("UPDATE anime_source SET normalized_url = ''");
    }

    private function store(): SourceUrlsFingerprintStore
    {
        return new SourceUrlsFingerprintStore($this->connection);
    }

    private function handler(LinkRulesProvider $provider): RecomputeSourceUrlsMessageHandler
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message) {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        $normalizer = new SourceUrlNormalizer($provider);

        return new RecomputeSourceUrlsMessageHandler(
            $this->connection,
            $provider,
            $normalizer,
            $this->store(),
            new SourceUrlsRecomputeScheduler($provider, $this->store(), $bus),
        );
    }
}
