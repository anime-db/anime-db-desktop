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
use AnimeDb\PluginContracts\Model\AnimeId as ContractAnimeId;
use AnimeDb\PluginContracts\Model\AnimeType as ContractAnimeType;
use AnimeDb\PluginContracts\Model\GenreCode as ContractGenreCode;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Enum\AnimeNameType;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\CatalogReader;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Exercises CatalogReader against a real EntityManager/SQLite connection (same setup as
 * BackfillExternalIdMessageHandlerTest) rather than mocking Anime — the merge into AnimeView and
 * the cached-vs-lazily-resolved externalId distinction both depend on real entity state.
 */
final class CatalogReaderTest extends TestCase
{
    private EntityManager $entityManager;

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
    }

    public function testReadReturnsNullForANonExistentRecord(): void
    {
        $reader = $this->newReader('fake-vendor');

        self::assertNull($reader->read(new ContractAnimeId(999999)));
    }

    public function testReadProjectsCoreFieldsFromTheEntity(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')
            ->setWatchStatus(WatchStatus::Watching)
            ->addName('Каубой Бибоп', AnimeNameType::Russian)
            ->addGenre(GenreCode::Action)
            ->addSource('https://shikimori.one/animes/1');
        $anime->setEpisodesCount(26);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $id = $this->requireId($anime);

        $view = $this->newReader('fake-vendor')->read(new ContractAnimeId($id));

        self::assertNotNull($view);
        self::assertSame('Cowboy Bebop', $view->title);
        self::assertSame(['Каубой Бибоп'], $view->alternativeNames);
        self::assertSame(ContractAnimeType::Tv, $view->type);
        self::assertSame([ContractGenreCode::Action], $view->genres);
        self::assertSame(26, $view->episodesCount);
        self::assertSame(['https://shikimori.one/animes/1'], $view->sources);
    }

    public function testEpisodesCountIsNullForANonSeriesRecord(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Your Name')->setWatchStatus(WatchStatus::Watching);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $id = $this->requireId($anime);

        $view = $this->newReader('fake-vendor')->read(new ContractAnimeId($id));

        self::assertNotNull($view);
        self::assertNull($view->episodesCount);
    }

    public function testExternalIdComesFromTheCacheWithoutCallingTheResolver(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching);
        $anime->rememberExternalId(new PluginId('fake-vendor'), 'cached-1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $id = $this->requireId($anime);

        $resolver = $this->createMock(ExternalIdResolutionInterface::class);
        $resolver->expects(self::never())->method('resolveExternalId');

        $view = $this->newReader('fake-vendor', $resolver)->read(new ContractAnimeId($id));

        self::assertSame('cached-1', $view?->externalId);
    }

    public function testExternalIdIsLazilyResolvedButNeverCached(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching)->addSource('https://shikimori.one/animes/1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $id = $this->requireId($anime);

        $resolver = $this->createMock(ExternalIdResolutionInterface::class);
        $resolver->expects(self::once())->method('resolveExternalId')
            ->with(['https://shikimori.one/animes/1'])
            ->willReturn('resolved-1');

        $view = $this->newReader('fake-vendor', $resolver)->read(new ContractAnimeId($id));
        self::assertSame('resolved-1', $view?->externalId);

        // read() must never persist what it resolves — it is injected into arbitrary plugin
        // services, including ones the host calls inside its own unfinished unit of work.
        $this->entityManager->clear();
        $reloaded = $this->requireAnime($id);
        self::assertNull($reloaded->getCachedExternalId(new PluginId('fake-vendor')));
    }

    public function testExternalIdIsNullWithoutCacheOrResolverAndDoesNotThrow(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Local Only')->setWatchStatus(WatchStatus::Watching);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $id = $this->requireId($anime);

        $view = $this->newReader('fake-vendor', null)->read(new ContractAnimeId($id));

        self::assertNotNull($view);
        self::assertNull($view->externalId);
    }

    public function testExternalIdIsNullWhenTheResolverThrows(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching)->addSource('https://shikimori.one/animes/1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $id = $this->requireId($anime);

        $resolver = $this->createStub(ExternalIdResolutionInterface::class);
        $resolver->method('resolveExternalId')->willThrowException(new \RuntimeException('boom'));

        $view = $this->newReader('fake-vendor', $resolver)->read(new ContractAnimeId($id));

        self::assertNotNull($view);
        self::assertNull($view->externalId);
    }

    public function testExternalIdIsScopedPerPluginForTheSameRecord(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching);
        $anime->rememberExternalId(new PluginId('fake-vendor'), 'vendor-one-id');
        $anime->rememberExternalId(new PluginId('fake-vendor-two'), 'vendor-two-id');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $id = $this->requireId($anime);

        $firstView = $this->newReader('fake-vendor')->read(new ContractAnimeId($id));
        $secondView = $this->newReader('fake-vendor-two')->read(new ContractAnimeId($id));

        self::assertSame('vendor-one-id', $firstView?->externalId);
        self::assertSame('vendor-two-id', $secondView?->externalId);
    }

    private function newReader(string $pluginId, ?ExternalIdResolutionInterface $resolver = null): CatalogReader
    {
        return new CatalogReader(
            new PluginId($pluginId),
            $this->registry(),
            $resolver !== null ? static fn (): ExternalIdResolutionInterface => $resolver : null,
            new NullLogger(),
        );
    }

    private function registry(): ManagerRegistry
    {
        $entityManager = $this->entityManager;

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        return $registry;
    }

    private function requireId(Anime $anime): int
    {
        return $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');
    }

    private function requireAnime(int $id): Anime
    {
        return $this->entityManager->find(Anime::class, $id) ?? throw new \LogicException(\sprintf('Anime #%d must exist.', $id));
    }
}
