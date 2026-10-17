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

use App\DomainEvent\Catalog\AnimeSnapshotNormalizer;
use App\Entity\Anime;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every mapping of Anime, of each class of its DiscriminatorMap and of each child entity must be
 * classified as domain or technical: a new field cannot silently fall out of the catalog events.
 */
final class AnimeSnapshotCompletenessTest extends TestCase
{
    /** @var array<string, ClassMetadata<object>> */
    private static array $metadata = [];

    public static function setUpBeforeClass(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 4).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        foreach ($entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            self::$metadata[$metadata->getName()] = $metadata;
        }
    }

    /** @return iterable<string, array{string}> */
    public static function animeClasses(): iterable
    {
        yield Anime::class => [Anime::class];
        $map = (new \ReflectionClass(Anime::class))->getAttributes(\Doctrine\ORM\Mapping\DiscriminatorMap::class)[0]->newInstance()->value;
        foreach ($map as $class) {
            yield $class => [$class];
        }
    }

    /** @param class-string $class */
    #[DataProvider('animeClasses')]
    public function testEveryAnimeMappingIsClassified(string $class): void
    {
        $classified = [
            ...AnimeSnapshotNormalizer::ANIME_DOMAIN_FIELDS,
            ...AnimeSnapshotNormalizer::ANIME_COLLECTION_FIELDS,
            ...AnimeSnapshotNormalizer::ANIME_TECHNICAL_FIELDS,
        ];

        $metadata = self::$metadata[$class];
        $mapped = [...array_keys($metadata->fieldMappings), ...array_keys($metadata->associationMappings)];
        // The discriminator column of a SINGLE_TABLE hierarchy is not a field mapping.
        $mapped[] = $metadata->discriminatorColumn->name ?? 'type';

        self::assertSame([], array_values(array_diff($mapped, $classified)), \sprintf('Unclassified mapping(s) of %s.', $class));
    }

    public function testEveryClassifiedAnimeFieldIsStillMapped(): void
    {
        $mapped = [];
        foreach (iterator_to_array(self::animeClasses(), false) as [$class]) {
            $metadata = self::$metadata[$class];
            $mapped = [...$mapped, ...array_keys($metadata->fieldMappings), ...array_keys($metadata->associationMappings), $metadata->discriminatorColumn->name ?? 'type'];
        }

        $classified = [
            ...AnimeSnapshotNormalizer::ANIME_DOMAIN_FIELDS,
            ...AnimeSnapshotNormalizer::ANIME_COLLECTION_FIELDS,
            ...AnimeSnapshotNormalizer::ANIME_TECHNICAL_FIELDS,
        ];

        self::assertSame([], array_values(array_diff($classified, $mapped)));
    }

    /** @return iterable<string, array{class-string}> */
    public static function childClasses(): iterable
    {
        foreach (AnimeSnapshotNormalizer::CHILD_CLASSES as $class) {
            yield $class => [$class];
        }
    }

    /** @param class-string $class */
    #[DataProvider('childClasses')]
    public function testEveryChildMappingIsClassified(string $class): void
    {
        $metadata = self::$metadata[$class];
        $mapped = [...array_keys($metadata->fieldMappings), ...array_keys($metadata->associationMappings)];
        $classified = [
            ...AnimeSnapshotNormalizer::CHILD_DOMAIN_FIELDS[$class],
            ...AnimeSnapshotNormalizer::CHILD_TECHNICAL_FIELDS[$class],
        ];

        self::assertSame([], array_values(array_diff($mapped, $classified)), \sprintf('Unclassified mapping(s) of %s.', $class));
        self::assertSame([], array_values(array_diff($classified, $mapped)), \sprintf('Classified but unmapped field(s) of %s.', $class));
    }

    public function testEveryChildEntityOfAnimeIsCovered(): void
    {
        $children = [];
        foreach (self::$metadata as $name => $metadata) {
            if (isset($metadata->associationMappings['anime']) && $metadata->associationMappings['anime']->targetEntity === Anime::class) {
                $children[] = $name;
            }
        }

        // AnimePluginData, AnimeSyncState, etc. also point at Anime; the ones below are the aggregate's own collections.
        $collections = [];
        foreach (self::$metadata[Anime::class]->associationMappings as $field => $mapping) {
            if ($mapping->isToMany()) {
                $collections[$field] = $mapping->targetEntity;
            }
        }

        foreach ($collections as $field => $target) {
            if (\in_array($field, ['studios', 'labels'], true)) {
                continue;
            }
            self::assertSame($target, AnimeSnapshotNormalizer::CHILD_CLASSES[$field] ?? null, \sprintf('Collection %s has no child classification.', $field));
        }
        self::assertContains(\App\Entity\AnimeName::class, $children);
    }
}
