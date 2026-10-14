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

namespace App\Tests\Unit\Service\Plugin;

use AnimeDb\PluginContracts\ExternalIdResolutionInterface;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\AnimeExternalId;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Message\ExternalIdConflictMessage;
use App\Repository\AnimeRepository;
use App\Service\Plugin\ExternalIdResolver;
use App\Tests\Support\RecordingSaturationBus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Contracts\Service\ResetInterface;

final class ExternalIdResolverTest extends TestCase
{
    private EntityManager $entityManager;
    private PluginId $pluginId;

    /** @var \ArrayObject<int, array{level: mixed, message: string, context: array<mixed>}> */
    private \ArrayObject $logs;

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
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->pluginId = new PluginId('animedb-shikimori');
        $this->logs = new \ArrayObject();
    }

    private function resolver(): ExternalIdResolver
    {
        $logger = new class($this->logs) extends AbstractLogger {
            /** @param \ArrayObject<int, array{level: mixed, message: string, context: array<mixed>}> $logs */
            public function __construct(private readonly \ArrayObject $logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs->append(['level' => $level, 'message' => (string) $message, 'context' => $context]);
            }
        };

        return new ExternalIdResolver(new AnimeRepository($this->entityManager), $logger);
    }

    private function anime(string $title, ?string $pinnedId = null): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle($title)->setWatchStatus(WatchStatus::Plan);
        $anime->addSource('https://shikimori.one/animes/X');
        if ($pinnedId !== null) {
            $anime->rememberExternalId($this->pluginId, $pinnedId);
        }
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    private function plugin(?string $id): ExternalIdResolutionInterface
    {
        $plugin = $this->createStub(ExternalIdResolutionInterface::class);
        $plugin->method('resolveExternalId')->willReturn($id);

        return $plugin;
    }

    public function testIdPinnedToAnotherAnimeIsNotCachedAndIsLogged(): void
    {
        $b = $this->anime('B', 'X');
        $a = $this->anime('A');

        $this->assertNull($this->resolver()->resolve($a, $this->pluginId, $this->plugin('X')));

        $this->assertNull($a->getCachedExternalId($this->pluginId));
        $this->entityManager->flush();
        $this->assertCount(1, $this->entityManager->getRepository(AnimeExternalId::class)->findAll());

        $this->assertCount(1, $this->logs);
        $entry = $this->logs[0] ?? throw new \LogicException('No log entry.');
        $this->assertSame('warning', $entry['level']);
        $this->assertSame(
            ['pluginId' => 'animedb-shikimori', 'externalId' => 'X', 'animeId' => $a->id, 'ownerId' => $b->id],
            $entry['context'],
        );
    }

    public function testFreeIdIsCached(): void
    {
        $a = $this->anime('A');

        $this->assertSame('X', $this->resolver()->resolve($a, $this->pluginId, $this->plugin('X')));

        $this->assertSame('X', $a->getCachedExternalId($this->pluginId));
        $this->entityManager->flush();
        $this->assertCount(1, $this->entityManager->getRepository(AnimeExternalId::class)->findAll());
        $this->assertCount(0, $this->logs);
    }

    public function testIdAlreadyPinnedToTheSameAnimeIsReturnedWithoutWarning(): void
    {
        $a = $this->anime('A', 'X');
        $this->entityManager->clear();
        $a = $this->entityManager->find(TvAnime::class, $a->id) ?? throw new \LogicException('missing');

        $this->assertSame('X', $this->resolver()->claim($a, new PluginId('animedb-shikimori'), 'X'));
        $this->assertCount(0, $this->logs);
    }

    public function testCachedIdIsReturnedWithoutCallingThePlugin(): void
    {
        $a = $this->anime('A', 'X');
        $plugin = $this->createMock(ExternalIdResolutionInterface::class);
        $plugin->expects($this->never())->method('resolveExternalId');

        $this->assertSame('X', $this->resolver()->resolve($a, $this->pluginId, $plugin));
    }

    public function testUnrecognisedIdReturnsNullWithoutCaching(): void
    {
        $a = $this->anime('A');

        $this->assertNull($this->resolver()->resolve($a, $this->pluginId, $this->plugin(null)));
        $this->assertNull($a->getCachedExternalId($this->pluginId));
    }

    public function testUnflushedClaimOfTheSameIdBlocksAnotherAnime(): void
    {
        $a = $this->anime('A');
        $b = $this->anime('B');
        $resolver = $this->resolver();

        $this->assertSame('X', $resolver->claim($a, $this->pluginId, 'X'));
        $this->assertNull($resolver->claim($b, $this->pluginId, 'X'));

        $this->assertNull($b->getCachedExternalId($this->pluginId));
        $this->entityManager->flush();
        $this->assertCount(1, $this->entityManager->getRepository(AnimeExternalId::class)->findAll());
        $this->assertCount(1, $this->logs);
    }

    public function testResetDropsUnflushedClaims(): void
    {
        $a = $this->anime('A');
        $b = $this->anime('B');
        $resolver = $this->resolver();

        $this->assertSame('X', $resolver->claim($a, $this->pluginId, 'X'));
        $resolver->reset();

        $this->assertSame('X', $resolver->claim($b, $this->pluginId, 'X'));
        $this->assertCount(0, $this->logs);
    }

    public function testResolverIsResettable(): void
    {
        $this->assertInstanceOf(ResetInterface::class, $this->resolver());
    }

    public function testSameAnimeLoadedByAnotherEntityManagerIsNotAForeignOwner(): void
    {
        $a = $this->anime('A', 'X');
        $this->entityManager->clear();

        // The claimant is one instance of anime #A with no cached id; the owner lookup runs through
        // another manager and yields a second instance of the very same row.
        $claimant = $this->entityManager->find(TvAnime::class, $a->id) ?? throw new \LogicException('missing');
        (new \ReflectionProperty(Anime::class, 'externalIds'))->setValue($claimant, new ArrayCollection());
        $this->assertNull($claimant->getCachedExternalId($this->pluginId));

        $config = $this->entityManager->getConfiguration();
        $other = new EntityManager($this->entityManager->getConnection(), $config);

        $this->assertSame('X', $this->resolver()->claim($claimant, $this->pluginId, 'X', $other));
        $this->assertCount(0, $this->logs);
    }

    public function testResolveDispatchesConflictMessageAfterCurrentBus(): void
    {
        $owner = $this->anime('Owner', 'X');
        $other = $this->anime('Other');
        $bus = new RecordingSaturationBus();
        $resolver = new ExternalIdResolver(new AnimeRepository($this->entityManager), new NullLogger(), $bus);

        $this->assertNull($resolver->resolve($other, $this->pluginId, $this->plugin('X')));

        $this->assertCount(1, $bus->dispatched);
        [$message, $stamps] = $bus->dispatched[0];
        $this->assertEquals(new ExternalIdConflictMessage('animedb-shikimori', 'X', (int) $owner->id, (int) $other->id), $message);
        $this->assertInstanceOf(DispatchAfterCurrentBusStamp::class, $stamps[0]);
        $this->assertNull($other->getCachedExternalId($this->pluginId));
    }

    public function testPublicClaimDoesNotDispatchConflictMessage(): void
    {
        $this->anime('Owner', 'X');
        $other = $this->anime('Other');
        $bus = new RecordingSaturationBus();
        $resolver = new ExternalIdResolver(new AnimeRepository($this->entityManager), new NullLogger(), $bus);

        $this->assertNull($resolver->claim($other, $this->pluginId, 'X'));
        $this->assertSame([], $bus->dispatched);
    }

    public function testNoMessageWhenAnimeHasNoIdYet(): void
    {
        $this->anime('Owner', 'X');
        $unsaved = new TvAnime();
        $unsaved->setTitle('New')->setWatchStatus(WatchStatus::Plan);
        $bus = new RecordingSaturationBus();
        $resolver = new ExternalIdResolver(new AnimeRepository($this->entityManager), new NullLogger(), $bus);

        $this->assertNull($resolver->resolve($unsaved, $this->pluginId, $this->plugin('X')));
        $this->assertSame([], $bus->dispatched);
    }
}
