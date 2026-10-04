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

namespace App\Tests\Unit\EventListener;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\AnimeNameRole;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\ThemeCode;
use App\Entity\Enum\WatchStatus;
use App\Entity\Label;
use App\Entity\MovieAnime;
use App\Entity\Studio;
use App\EventListener\AnimeAggregateTouchListener;
use App\EventListener\AnimeSearchIndexListener;
use App\Message\IndexAnimeMessage;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Exercises the issue #888 fix against a real EntityManager (a plain mock of the Doctrine
 * lifecycle, unlike AnimeTest's direct onPreUpdate() call, would not catch either half of the
 * original bug: Anime missing #[ORM\HasLifecycleCallbacks] meant Doctrine never invoked that
 * callback at all, and a OneToMany child never schedules its parent for an UPDATE in the first
 * place).
 *
 * Both App\EventListener\AnimeSearchIndexListener (postUpdate) and AnimeAggregateTouchListener
 * (onFlush) are wired onto the same EventManager, same as DoctrineBundle would autoconfigure
 * them in production — the IndexAnimeMessage assertions below only hold because touching a
 * child collection makes AnimeAggregateTouchListener force Anime into the update batch, which
 * is what makes AnimeSearchIndexListener's own postUpdate fire for it.
 */
final class AnimeAggregateTouchListenerTest extends TestCase
{
    private EntityManager $entityManager;
    private MessageBusInterface&\PHPUnit\Framework\MockObject\MockObject $messageBus;

    protected function setUp(): void
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 3).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->messageBus = $this->createMock(MessageBusInterface::class);

        $eventManager = $this->entityManager->getEventManager();
        $eventManager->addEventListener(Events::onFlush, new AnimeAggregateTouchListener());
        $eventManager->addEventListener(Events::postUpdate, new AnimeSearchIndexListener($this->messageBus));
    }

    public function testChangingAScalarFieldBumpsDateUpdate(): void
    {
        $anime = $this->persistAnime();
        $before = $anime->getDateUpdate();

        // Not under test here, but postUpdate fires regardless and the mock must return
        // something: Envelope is final and cannot be auto-stubbed by PHPUnit.
        $this->messageBus->expects($this->atLeastOnce())->method('dispatch')->willReturnCallback(
            static fn (object $message): Envelope => new Envelope($message),
        );

        usleep(1_000);
        $anime->setTitle('Cowboy Bebop: Remastered');
        $this->entityManager->flush();

        $this->assertGreaterThan($before, $anime->getDateUpdate());
    }

    public function testAddingANameBumpsDateUpdateAndDispatchesIndexExactlyOnce(): void
    {
        $anime = $this->persistAnime();
        $before = $anime->getDateUpdate();
        $animeId = $this->requireId($anime);

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo(new IndexAnimeMessage($animeId)))
            ->willReturn(new Envelope(new IndexAnimeMessage($animeId)));

        usleep(1_000);
        $anime->addName('Cowboy Bebop', 'en', AnimeNameRole::Synonym);
        $this->entityManager->flush();

        $this->assertGreaterThan($before, $anime->getDateUpdate());
    }

    public function testAddingAGenreBumpsDateUpdateAndDispatchesIndexExactlyOnce(): void
    {
        $anime = $this->persistAnime();
        $before = $anime->getDateUpdate();
        $animeId = $this->requireId($anime);

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo(new IndexAnimeMessage($animeId)))
            ->willReturn(new Envelope(new IndexAnimeMessage($animeId)));

        usleep(1_000);
        $anime->addGenre(GenreCode::Action);
        $this->entityManager->flush();

        $this->assertGreaterThan($before, $anime->getDateUpdate());
    }

    public function testAddingAThemeBumpsDateUpdateAndDispatchesIndexExactlyOnce(): void
    {
        $anime = $this->persistAnime();
        $before = $anime->getDateUpdate();
        $animeId = $this->requireId($anime);

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo(new IndexAnimeMessage($animeId)))
            ->willReturn(new Envelope(new IndexAnimeMessage($animeId)));

        usleep(1_000);
        $anime->addTheme(ThemeCode::AdultCast);
        $this->entityManager->flush();

        $this->assertGreaterThan($before, $anime->getDateUpdate());
    }

    /**
     * Unlike the OneToMany children above, studios/labels are owning-side ManyToMany
     * collections: Doctrine's own UnitOfWork::computeChangeSet() already schedules the owning
     * Anime for an UPDATE whenever such a collection is dirty, so AnimeAggregateTouchListener
     * does nothing extra here. This guards that native behavior against regressions.
     */
    public function testAddingAStudioBumpsDateUpdateAndDispatchesIndexExactlyOnce(): void
    {
        $anime = $this->persistAnime();
        $before = $anime->getDateUpdate();
        $animeId = $this->requireId($anime);

        $studio = new Studio();
        $studio->rename('Sunrise');
        $this->entityManager->persist($studio);
        $this->entityManager->flush();

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo(new IndexAnimeMessage($animeId)))
            ->willReturn(new Envelope(new IndexAnimeMessage($animeId)));

        usleep(1_000);
        $anime->addStudio($studio);
        $this->entityManager->flush();

        $this->assertGreaterThan($before, $anime->getDateUpdate());
    }

    public function testAddingALabelBumpsDateUpdateAndDispatchesIndexExactlyOnce(): void
    {
        $anime = $this->persistAnime();
        $before = $anime->getDateUpdate();
        $animeId = $this->requireId($anime);

        $label = new Label('Favorite');
        $this->entityManager->persist($label);
        $this->entityManager->flush();

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo(new IndexAnimeMessage($animeId)))
            ->willReturn(new Envelope(new IndexAnimeMessage($animeId)));

        usleep(1_000);
        $anime->addLabel($label);
        $this->entityManager->flush();

        $this->assertGreaterThan($before, $anime->getDateUpdate());
    }

    private function persistAnime(): MovieAnime
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);

        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    private function requireId(MovieAnime $anime): int
    {
        return $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');
    }
}
