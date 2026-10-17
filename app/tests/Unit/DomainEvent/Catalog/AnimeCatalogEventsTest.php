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

namespace App\Tests\Unit\DomainEvent\Catalog;

use App\Doctrine\DomainEventConnection;
use App\Doctrine\DomainEventEntityManager;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\DomainEvent\Catalog\AnimeChangeCollector;
use App\DomainEvent\Catalog\AnimeSnapshotNormalizer;
use App\DomainEvent\DomainEventPublisher;
use App\Entity\Anime;
use App\Entity\Enum\AnimeNameRole;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\ThemeCode;
use App\Entity\Enum\WatchStatus;
use App\Entity\Label;
use App\Entity\MovieAnime;
use App\Entity\Storage;
use App\Entity\Studio;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Entity\ValueObject\Rating;
use App\Event\Catalog\AnimeAdded;
use App\Event\Catalog\AnimeChanged;
use App\Event\Catalog\AnimeRemoved;
use App\EventListener\DomainEventListener;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** The catalog events end to end over an in-memory SQLite, wired like the container wires them. */
final class AnimeCatalogEventsTest extends TestCase
{
    private DomainEventEntityManager $entityManager;
    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        foreach ([UnixTimestampType::NAME => UnixTimestampType::class, RatingType::NAME => RatingType::class] as $name => $class) {
            if (!Type::hasType($name)) {
                Type::addType($name, $class);
            }
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 4).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(\CASE_LOWER));

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'wrapperClass' => DomainEventConnection::class,
        ], $config);
        $connection->executeStatement('PRAGMA foreign_keys = ON');

        $dispatcher = new EventDispatcher();
        foreach ([AnimeAdded::class, AnimeChanged::class, AnimeRemoved::class] as $class) {
            $dispatcher->addListener($class, function (object $event): void {
                $this->events[] = $event;
            });
        }
        $publisher = new DomainEventPublisher($dispatcher, new NullLogger());
        $connection->setDomainEventPublisher($publisher);

        $raw = new EntityManager($connection, $config);
        $raw->getEventManager()->addEventListener(
            [Events::onFlush, Events::postFlush],
            new DomainEventListener($publisher, new AnimeChangeCollector(new AnimeSnapshotNormalizer())),
        );
        $this->entityManager = new DomainEventEntityManager($raw, $publisher);

        (new SchemaTool($raw))->createSchema($raw->getMetadataFactory()->getAllMetadata());
    }

    public function testPersistAndFlushGiveOneAddedWithTheId(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Added')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        self::assertCount(1, $this->events);
        self::assertInstanceOf(AnimeAdded::class, $this->events[0]);
        self::assertSame($anime->id, $this->events[0]->animeId);
        self::assertNotNull($anime->id);
    }

    public function testRemoveGivesSnapshotWithEveryDomainField(): void
    {
        $storage = new Storage('Disk', '/tmp', StorageType::Folder);
        $this->entityManager->persist($storage);
        $studio = $this->studio('Studio A');
        $label = new Label('Fav');
        $this->entityManager->persist($label);

        $anime = new TvAnime();
        $anime->setTitle('Series')->setWatchStatus(WatchStatus::Plan);
        $anime->setEpisodesCount(12)->setWatchedEpisodes(3)->setUserRating(new Rating(4))->setNotes('note')
            ->setCountries(['JP'])->setStorage($storage)->setStoragePath('Series')->setCover('c.jpg')
            ->setDatePremiere(new \DateTimeImmutable('2020-01-02T03:04:05+00:00'));
        $anime->addName('Alt', 'en', AnimeNameRole::Synonym)->addGenre(GenreCode::Action)->addTheme(ThemeCode::Mecha)
            ->addStudio($studio)->addLabel($label)->setDescription('en', 'text', 'src')
            ->addSource('https://example.com/a')->addImage('i.jpg')->rememberExternalId(new PluginId('some-plugin'), '42');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $id = $anime->id;
        $this->events = [];
        $this->entityManager->clear();

        $loaded = $this->entityManager->find(Anime::class, $id);
        self::assertNotNull($loaded);
        $this->entityManager->remove($loaded);
        $this->entityManager->flush();

        $delivered = $this->delivered();
        self::assertCount(1, $delivered);
        $event = $delivered[0];
        self::assertInstanceOf(AnimeRemoved::class, $event);
        self::assertSame($id, $event->animeId);
        $fields = $event->snapshot->fields;
        self::assertSame('tv', $fields['type']);
        self::assertSame('Series', $fields['title']);
        self::assertSame(12, $fields['episodesCount']);
        self::assertSame(3, $fields['watchedEpisodes']);
        self::assertSame(4, $fields['userRating']);
        self::assertSame('watching', $fields['watchStatus']);
        self::assertSame('note', $fields['notes']);
        self::assertSame(['JP'], $fields['countries']);
        self::assertSame('2020-01-02T03:04:05+00:00', $fields['datePremiere']);
        self::assertSame($storage->id, $fields['storage']);
        self::assertSame('Series', $fields['storagePath']);
        self::assertSame([['name' => 'Studio A']], $fields['studios']);
        self::assertSame([['name' => 'Fav']], $fields['labels']);
        self::assertSame([['name' => 'Alt', 'locale' => 'en', 'role' => 'synonym']], $fields['names']);
        self::assertSame([['code' => 'action']], $fields['genres']);
        self::assertSame([['code' => 'mecha']], $fields['themes']);
        self::assertSame([['locale' => 'en', 'description' => 'text', 'source' => 'src']], $fields['descriptions']);
        self::assertSame([['url' => 'https://example.com/a']], $fields['sources']);
        self::assertSame([['source' => 'i.jpg']], $fields['images']);
        self::assertSame([['pluginId' => 'some-plugin', 'externalId' => '42']], $fields['externalIds']);
        foreach (['id', 'dateAdd', 'dateUpdate', 'normalizedTitle', 'filesCheckedAt'] as $technical) {
            self::assertArrayNotHasKey($technical, $fields);
        }
    }

    public function testScalarAndSubclassFieldsChange(): void
    {
        $anime = $this->persistSeries('Scalar');

        $anime->setTitle('Renamed');
        $anime->setEpisodesCount(24)->setWatchedEpisodes(5);
        $this->entityManager->flush();

        $changes = $this->singleChange($anime);
        self::assertEqualsCanonicalizing(['title', 'watchStatus', 'episodesCount', 'watchedEpisodes'], array_keys($changes));
        self::assertSame(['Scalar', 'Renamed'], [$changes['title']->before, $changes['title']->after]);
        self::assertSame([12, 24], [$changes['episodesCount']->before, $changes['episodesCount']->after]);
        self::assertSame([null, 5], [$changes['watchedEpisodes']->before, $changes['watchedEpisodes']->after]);
    }

    public function testUnlinkingTheFolderIsReported(): void
    {
        $storage = new Storage('Disk', '/tmp', StorageType::Folder);
        $this->entityManager->persist($storage);
        $anime = $this->persistMovie('Linked', static fn (Anime $a) => $a->setStorage($storage)->setStoragePath('Linked'));

        $anime->setStorage(null)->setStoragePath(null);
        $this->entityManager->flush();

        $changes = $this->singleChange($anime);
        self::assertEqualsCanonicalizing(['storage', 'storagePath'], array_keys($changes));
        self::assertSame([$storage->id, null], [$changes['storage']->before, $changes['storage']->after]);
        self::assertSame(['Linked', null], [$changes['storagePath']->before, $changes['storagePath']->after]);
    }

    public function testChildCollectionsChangeWithTheirOwnBefore(): void
    {
        $anime = $this->persistMovie('Children', static function (Anime $a): void {
            $a->addName('One', 'en', AnimeNameRole::Synonym)->addGenre(GenreCode::Action)->addTheme(ThemeCode::Mecha)
                ->setDescription('en', 'old', null)->addSource('https://example.com/a')->addImage('a.jpg')
                ->rememberExternalId(new PluginId('some-plugin'), '1');
        });

        $anime->addName('Two', null, AnimeNameRole::Short)->addGenre(GenreCode::Drama)->addTheme(ThemeCode::Music)
            ->setDescription('en', 'new', 'src')->addSource('https://example.com/b')->addImage('b.jpg')
            ->rememberExternalId(new PluginId('other-plugin'), '2');
        $this->entityManager->flush();

        $changes = $this->singleChange($anime);
        self::assertEqualsCanonicalizing(['names', 'genres', 'themes', 'descriptions', 'sources', 'externalIds', 'images'], array_keys($changes));
        self::assertSame([['code' => 'action']], $changes['genres']->before);
        self::assertSame([['code' => 'action'], ['code' => 'drama']], $changes['genres']->after);
        // The description was edited in place: "before" must keep the old text, not the already modified object's.
        self::assertSame([['locale' => 'en', 'description' => 'old', 'source' => null]], $changes['descriptions']->before);
        self::assertSame([['locale' => 'en', 'description' => 'new', 'source' => 'src']], $changes['descriptions']->after);
        self::assertCount(1, $changes['images']->before);
        self::assertCount(2, $changes['images']->after);
        self::assertCount(1, $changes['externalIds']->before);
        self::assertCount(2, $changes['externalIds']->after);
    }

    public function testRemovedChildAppearsInBeforeOnly(): void
    {
        $anime = $this->persistMovie('Removing', static fn (Anime $a) => $a->addGenre(GenreCode::Action)->addGenre(GenreCode::Drama));

        $anime->removeGenre(GenreCode::Drama);
        $this->entityManager->flush();

        $changes = $this->singleChange($anime);
        self::assertSame(['genres'], array_keys($changes));
        self::assertSame([['code' => 'action'], ['code' => 'drama']], $changes['genres']->before);
        self::assertSame([['code' => 'action']], $changes['genres']->after);
    }

    public function testStudiosAndLabelsCompositionChanges(): void
    {
        $studio = $this->studio('Studio A');
        $label = new Label('Fav');
        $this->entityManager->persist($label);
        $anime = $this->persistMovie('M2M', static fn (Anime $a) => $a->addStudio($studio)->addLabel($label));
        $other = $this->studio('Studio B');

        $anime->addStudio($other)->removeLabel($label);
        $this->entityManager->flush();

        $changes = $this->singleChange($anime);
        self::assertEqualsCanonicalizing(['studios', 'labels'], array_keys($changes));
        self::assertSame([['name' => 'Studio A']], $changes['studios']->before);
        self::assertSame([['name' => 'Studio A'], ['name' => 'Studio B']], $changes['studios']->after);
        self::assertSame([['name' => 'Fav']], $changes['labels']->before);
        self::assertSame([], $changes['labels']->after);
    }

    public function testTechnicalOnlyChangesGiveNoEvent(): void
    {
        $anime = $this->persistMovie('Technical', static fn (Anime $a) => $a->rememberExternalId(new PluginId('some-plugin'), '1')->addSource('https://example.com/a'));

        $anime->markFilesChecked();
        $anime->touchDateUpdate();
        $anime->markExternalIdFilled(new PluginId('some-plugin'));
        $this->entityManager->flush();

        self::assertSame([], $this->events);
    }

    public function testDeleteAfterAddInOneDeliveryGivesNothing(): void
    {
        $this->entityManager->getConnection()->beginTransaction();
        $anime = $this->persistMovie('Short-lived');
        $this->entityManager->remove($anime);
        $this->entityManager->flush();
        $this->entityManager->getConnection()->commit();

        self::assertSame([], $this->events);
    }

    public function testTwoChangesInOneDeliveryGiveOneChanged(): void
    {
        $anime = $this->persistMovie('Twice');

        $this->entityManager->getConnection()->beginTransaction();
        $anime->setTitle('Second');
        $this->entityManager->flush();
        $anime->setTitle('Third')->setNotes('n');
        $this->entityManager->flush();
        self::assertSame([], $this->events);
        $this->entityManager->getConnection()->commit();

        $changes = $this->singleChange($anime);
        self::assertSame(['Twice', 'Third'], [$changes['title']->before, $changes['title']->after]);
        self::assertSame([null, 'n'], [$changes['notes']->before, $changes['notes']->after]);
    }

    /** @param (callable(Anime): mixed)|null $configure */
    private function persistMovie(string $title, ?callable $configure = null): MovieAnime
    {
        $anime = new MovieAnime();
        $anime->setTitle($title)->setWatchStatus(WatchStatus::Plan);
        if ($configure !== null) {
            $configure($anime);
        }

        return $this->persistAndForget($anime);
    }

    private function persistSeries(string $title): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle($title)->setWatchStatus(WatchStatus::Plan);
        $anime->setEpisodesCount(12);

        return $this->persistAndForget($anime);
    }

    /**
     * @template T of Anime
     *
     * @param T $anime
     *
     * @return T
     */
    private function persistAndForget(Anime $anime): Anime
    {
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $this->events = [];

        return $anime;
    }

    /** @return list<object> */
    private function delivered(): array
    {
        return $this->events;
    }

    private function studio(string $name): Studio
    {
        $studio = new Studio();
        $studio->rename($name);
        $this->entityManager->persist($studio);

        return $studio;
    }

    /** @return array<string, \App\Event\Catalog\FieldChange> */
    private function singleChange(Anime $anime): array
    {
        $delivered = $this->delivered();
        self::assertCount(1, $delivered);
        $event = $delivered[0];
        self::assertInstanceOf(AnimeChanged::class, $event);
        self::assertSame($anime->id, $event->animeId);
        foreach ($event->changes as $change) {
            self::assertNotSame($change->before, $change->after);
        }

        return $event->changes;
    }
}
