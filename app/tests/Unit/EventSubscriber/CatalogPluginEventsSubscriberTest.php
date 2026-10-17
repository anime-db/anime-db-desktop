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

namespace App\Tests\Unit\EventSubscriber;

use AnimeDb\PluginContracts\Catalog\AnimeAddedEvent;
use AnimeDb\PluginContracts\Catalog\AnimeChangedEvent;
use AnimeDb\PluginContracts\Catalog\AnimeField;
use AnimeDb\PluginContracts\Catalog\AnimeFilesChangedEvent;
use AnimeDb\PluginContracts\Catalog\AnimeRemovedEvent;
use AnimeDb\PluginContracts\Catalog\FilesChangeReason;
use App\Doctrine\DomainEventConnection;
use App\Doctrine\DomainEventEntityManager;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\DomainEvent\Catalog\AnimeChangeCollector;
use App\DomainEvent\Catalog\AnimeEventRecorder;
use App\DomainEvent\Catalog\AnimeSnapshotNormalizer;
use App\DomainEvent\DomainEventPublisher;
use App\Entity\Enum\AnimeNameRole;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Entity\Storage;
use App\Entity\ValueObject\PluginId;
use App\Entity\ValueObject\Rating;
use App\EventListener\DomainEventListener;
use App\EventSubscriber\CatalogPluginEventsSubscriber;
use App\Service\Plugin\AnimeFieldProjector;
use App\Service\Plugin\Filler\PluginDescriptionWriter;
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

/**
 * The plugin-facing catalog events end to end: a real EntityManager over SQLite, the real
 * publisher and {@see CatalogPluginEventsSubscriber}, plugin-side listeners recording what arrives.
 */
final class CatalogPluginEventsSubscriberTest extends TestCase
{
    private DomainEventEntityManager $entityManager;
    private DomainEventPublisher $publisher;
    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        foreach ([UnixTimestampType::NAME => UnixTimestampType::class, RatingType::NAME => RatingType::class] as $name => $class) {
            if (!Type::hasType($name)) {
                Type::addType($name, $class);
            }
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 3).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(\CASE_LOWER));

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'wrapperClass' => DomainEventConnection::class,
        ], $config);
        $connection->executeStatement('PRAGMA foreign_keys = ON');

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new CatalogPluginEventsSubscriber($dispatcher, new AnimeFieldProjector()));
        foreach ([AnimeAddedEvent::class, AnimeChangedEvent::class, AnimeRemovedEvent::class, AnimeFilesChangedEvent::class] as $class) {
            $dispatcher->addListener($class, function (object $event): void {
                $this->events[] = $event;
            });
        }

        $this->publisher = $publisher = new DomainEventPublisher($dispatcher, new NullLogger());
        $connection->setDomainEventPublisher($publisher);
        $raw = new EntityManager($connection, $config);
        $raw->getEventManager()->addEventListener(
            [Events::onFlush, Events::postFlush],
            new DomainEventListener($publisher, new AnimeChangeCollector(new AnimeSnapshotNormalizer())),
        );
        $this->entityManager = new DomainEventEntityManager($raw, $publisher);

        (new SchemaTool($raw))->createSchema($raw->getMetadataFactory()->getAllMetadata());
    }

    public function testAddedRecordGivesAnAddedEventAndNoChangedEvent(): void
    {
        $anime = $this->record();

        self::assertCount(1, $this->events);
        self::assertInstanceOf(AnimeAddedEvent::class, $this->first());
        self::assertSame($anime->id, $this->first()->anime->value);
    }

    public function testEditingFormFieldsGivesTheirVocabularyKeys(): void
    {
        $anime = $this->record();
        $this->events = [];

        $anime->setTitle('Renamed')->setDurationMinutes(24)->setCountries(['JP'])->addName('Alt', 'en', AnimeNameRole::Synonym);
        $this->entityManager->flush();

        $changed = $this->changedKeys();
        self::assertEqualsCanonicalizing(['title', 'duration_minutes', 'countries', 'alternative_names'], $changed);
        $event = $this->first();
        self::assertInstanceOf(AnimeChangedEvent::class, $event);
        self::assertSame(['Cowboy Bebop', 'Renamed'], [$event->changes['title']->before, $event->changes['title']->after]);
    }

    public function testCoverUploadGivesTheCoverKey(): void
    {
        $anime = $this->record();
        $this->events = [];

        $anime->setCover('7/cover.jpg');
        $this->entityManager->flush();

        $event = $this->first();
        self::assertInstanceOf(AnimeChangedEvent::class, $event);
        self::assertSame(['cover'], array_keys($event->changes));
        self::assertSame('cover.jpg', $event->changes['cover']->after);
    }

    public function testDescriptionWrittenByAPluginGivesTheDescriptionKeyAfterTheFlush(): void
    {
        $anime = $this->record();
        $this->events = [];

        $recorder = new AnimeEventRecorder($this->publisher, new AnimeSnapshotNormalizer(), $this->entityManager);
        (new PluginDescriptionWriter($this->entityManager, null, $recorder))->write($anime, new PluginId('animedb-shikimori'), 'en', 'From plugin');
        $this->entityManager->flush();

        self::assertSame([AnimeField::Description->value], $this->changedKeys());
        $event = $this->first();
        self::assertInstanceOf(AnimeChangedEvent::class, $event);
        self::assertSame(['en' => 'From plugin'], $event->changes['description']->after);
    }

    public function testChangesOutsideTheVocabularyGiveNoChangedEvent(): void
    {
        $anime = $this->record();
        $this->events = [];

        $anime->setWatchStatus(WatchStatus::Watching)->setUserRating(new Rating(5))->setNotes('private')->addImage('gallery.jpg');
        $this->entityManager->flush();

        self::assertSame([], $this->events);
    }

    public function testNotesOnlyChangeGivesNoChangedEvent(): void
    {
        $anime = $this->record();
        $this->events = [];

        $anime->setNotes('only notes');
        $this->entityManager->flush();

        self::assertSame([], $this->events);
    }

    public function testUnlinkingFromTheStorageGivesUnlinkedAfterTheCommitAndNoChangedEvent(): void
    {
        $storage = new Storage('Disk', '/tmp', StorageType::Folder);
        $this->entityManager->persist($storage);
        $anime = $this->record(static fn (MovieAnime $anime) => $anime->setStorage($storage)->setStoragePath('Folder'));
        $this->events = [];

        $anime->setStorage(null)->setStoragePath(null);
        $this->entityManager->flush();

        self::assertCount(1, $this->events);
        self::assertInstanceOf(AnimeFilesChangedEvent::class, $this->first());
        self::assertSame(FilesChangeReason::Unlinked, $this->first()->reason);
    }

    public function testChangingTheFolderOrFieldsOfARecordWithoutAFolderGivesNoUnlinked(): void
    {
        $storage = new Storage('Disk', '/tmp', StorageType::Folder);
        $this->entityManager->persist($storage);
        $anime = $this->record(static fn (MovieAnime $anime) => $anime->setStorage($storage)->setStoragePath('Folder'));
        $plain = $this->record();
        $this->events = [];

        $anime->setStoragePath('Other');
        $plain->setTitle('Another title');
        $this->entityManager->flush();

        foreach ($this->delivered() as $event) {
            self::assertNotInstanceOf(AnimeFilesChangedEvent::class, $event);
        }
    }

    public function testRemovedRecordGivesEveryVocabularyKeyOfItsLastValues(): void
    {
        $anime = $this->record();
        $id = $anime->id;
        $this->events = [];

        $this->entityManager->remove($anime);
        $this->entityManager->flush();

        self::assertCount(1, $this->events);
        $event = $this->first();
        self::assertInstanceOf(AnimeRemovedEvent::class, $event);
        self::assertSame($id, $event->anime->value);
        self::assertEqualsCanonicalizing(array_map(static fn (AnimeField $field): string => $field->value, AnimeField::cases()), array_keys($event->lastValues));
        self::assertSame('Cowboy Bebop', $event->lastValues['title']);
    }

    /** @return list<object> */
    private function delivered(): array
    {
        return $this->events;
    }

    private function first(): object
    {
        return $this->events[0] ?? throw new \LogicException('No event was delivered.');
    }

    /** @return list<string> */
    private function changedKeys(): array
    {
        self::assertCount(1, $this->events);
        self::assertInstanceOf(AnimeChangedEvent::class, $this->first());

        return array_keys($this->first()->changes);
    }

    private function record(?\Closure $configure = null): MovieAnime
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        if ($configure !== null) {
            $configure($anime);
        }
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }
}
