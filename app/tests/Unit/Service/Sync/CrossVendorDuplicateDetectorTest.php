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

namespace App\Tests\Unit\Service\Sync;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\Enum\WatchStatus;
use App\Entity\SyncReviewItem;
use App\Entity\TvAnime;
use App\Repository\SyncReviewItemRepository;
use App\Service\Search\AnimeSearchMatch;
use App\Service\Search\AnimeSearchResolver;
use App\Service\Sync\CrossVendorDuplicateDetector;
use App\Service\Sync\SyncReviewService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the three cases issue #268 calls out explicitly: a match above the conservative
 * threshold raises a PotentialDuplicate cluster, a match below it raises nothing, and a
 * Meilisearch outage (AnimeSearchResolver::tryResolveMatches() returning null) is skipped
 * without failing the run.
 */
final class CrossVendorDuplicateDetectorTest extends TestCase
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

    public function testAMatchAboveTheThresholdRaisesAPotentialDuplicateClusterInTheStore(): void
    {
        $existing = $this->persistAnime('Trigun');
        $anime = $this->persistAnime('Trigun');

        $resolver = $this->createMock(AnimeSearchResolver::class);
        $resolver->expects($this->once())
            ->method('tryResolveMatches')
            ->with('Trigun')
            ->willReturn([new AnimeSearchMatch($this->requireId($existing), 0.95)]);

        $this->detector($resolver)->detect($anime);

        $items = $this->allReviewItems();
        $this->assertCount(1, $items);
        $this->assertSame(SyncReviewItemKind::PotentialDuplicate, $items[0]->kind);
        $this->assertSame(['anime_ids' => [$existing->id, $anime->id]], $items[0]->payload);
    }

    public function testAMatchBelowTheThresholdRaisesNothing(): void
    {
        $existing = $this->persistAnime('Trigun');
        $anime = $this->persistAnime('Trigun');

        $resolver = $this->createMock(AnimeSearchResolver::class);
        $resolver->expects($this->once())
            ->method('tryResolveMatches')
            ->with('Trigun')
            ->willReturn([new AnimeSearchMatch($this->requireId($existing), 0.5)]);

        $this->detector($resolver)->detect($anime);

        $this->assertSame([], $this->allReviewItems());
    }

    public function testMeilisearchBeingUnreachableSkipsTheHeuristicWithoutFailing(): void
    {
        $anime = $this->persistAnime('Trigun');

        $resolver = $this->createMock(AnimeSearchResolver::class);
        $resolver->expects($this->once())->method('tryResolveMatches')->willReturn(null);

        $this->detector($resolver)->detect($anime);

        $this->assertSame([], $this->allReviewItems());
    }

    private function detector(AnimeSearchResolver $resolver): CrossVendorDuplicateDetector
    {
        return new CrossVendorDuplicateDetector(
            $resolver,
            new SyncReviewService(new SyncReviewItemRepository($this->entityManager)),
        );
    }

    private function persistAnime(string $title): Anime
    {
        $anime = new TvAnime();
        $anime->setTitle($title)->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    private function requireId(Anime $anime): int
    {
        return $anime->id ?? throw new \LogicException('id must be set after flush');
    }

    /** @return list<SyncReviewItem> */
    private function allReviewItems(): array
    {
        /* @var list<SyncReviewItem> */
        return $this->entityManager->getRepository(SyncReviewItem::class)->findAll();
    }
}
