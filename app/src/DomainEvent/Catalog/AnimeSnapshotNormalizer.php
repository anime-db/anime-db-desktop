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

namespace App\DomainEvent\Catalog;

use App\Entity\Anime;
use App\Entity\AnimeDescription;
use App\Entity\AnimeExternalId;
use App\Entity\AnimeGenre;
use App\Entity\AnimeImage;
use App\Entity\AnimeName;
use App\Entity\AnimeSource;
use App\Entity\AnimeTheme;
use App\Entity\Label;
use App\Entity\Storage;
use App\Entity\Studio;
use App\Entity\ValueObject\Rating;
use App\Event\Catalog\AnimeSnapshot;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns the values of an Anime aggregate into plain data for the catalog domain events: scalars
 * stay as they are, enums become their value, dates ISO 8601 (UTC), a collection a sorted list of
 * arrays. Also builds the {@see AnimeSnapshot}.
 *
 * Every mapped field of Anime, of the subclasses in its DiscriminatorMap and of the child entities
 * is listed below either as domain (goes into the events) or as technical (never does); the
 * completeness test fails on a mapping that is in neither list.
 */
final class AnimeSnapshotNormalizer
{
    /** Domain fields of Anime and its subclasses; `type` is the discriminator column (not a mapped field). */
    public const ANIME_DOMAIN_FIELDS = [
        'type',
        'title',
        'datePremiere',
        'dateEnd',
        'durationMinutes',
        'watchStatus',
        'userRating',
        'notes',
        'countries',
        'cover',
        'storage',
        'storagePath',
        'demographic',
        'episodesCount',
        'watchedEpisodes',
    ];

    /** Child collections and M2M collections of Anime (domain). */
    public const ANIME_COLLECTION_FIELDS = [
        'names',
        'genres',
        'themes',
        'studios',
        'labels',
        'descriptions',
        'sources',
        'externalIds',
        'images',
    ];

    public const ANIME_TECHNICAL_FIELDS = [
        'id',
        'normalizedTitle',
        'dateAdd',
        'dateUpdate',
        'filesCheckedAt',
        'watchProgressUpdatedAt',
        'watchProgressRejectedAt',
    ];

    /** Collection field of Anime => its child entity class. */
    public const CHILD_CLASSES = [
        'names' => AnimeName::class,
        'genres' => AnimeGenre::class,
        'themes' => AnimeTheme::class,
        'descriptions' => AnimeDescription::class,
        'sources' => AnimeSource::class,
        'externalIds' => AnimeExternalId::class,
        'images' => AnimeImage::class,
    ];

    /** Domain fields of the child entities (the back reference `anime` is structural and listed as technical). */
    public const CHILD_DOMAIN_FIELDS = [
        AnimeName::class => ['name', 'locale', 'role'],
        AnimeGenre::class => ['code'],
        AnimeTheme::class => ['code'],
        AnimeDescription::class => ['locale', 'description', 'source'],
        AnimeSource::class => ['url'],
        AnimeExternalId::class => ['pluginId', 'externalId'],
        AnimeImage::class => ['source'],
    ];

    public const CHILD_TECHNICAL_FIELDS = [
        AnimeName::class => ['id', 'anime', 'normalizedName'],
        AnimeGenre::class => ['anime'],
        AnimeTheme::class => ['anime'],
        AnimeDescription::class => ['id', 'anime'],
        AnimeSource::class => ['id', 'anime', 'normalizedUrl'],
        AnimeExternalId::class => ['anime', 'filledAt'],
        AnimeImage::class => ['id', 'anime'],
    ];

    /** Plain values of one domain field of an Anime (everything except the collections). */
    public function normalizeAnimeField(Anime $anime, string $field, EntityManagerInterface $entityManager): mixed
    {
        $metadata = $entityManager->getClassMetadata($anime::class);
        if ($field === 'type') {
            return $metadata->discriminatorValue;
        }

        return $this->normalizeValue($metadata->getFieldValue($anime, $field));
    }

    /**
     * One element of a child collection. With $original (the element's getOriginalEntityData())
     * the values are the ones loaded from the database, otherwise the current ones.
     *
     * @param array<string, mixed>|null $original
     *
     * @return array<string, mixed>
     */
    public function normalizeChild(object $child, EntityManagerInterface $entityManager, ?array $original = null): array
    {
        $metadata = $entityManager->getClassMetadata($child::class);

        $values = [];
        foreach (self::CHILD_DOMAIN_FIELDS[$child::class] as $field) {
            $values[$field] = $this->normalizeValue(
                $original !== null && \array_key_exists($field, $original) ? $original[$field] : $metadata->getFieldValue($child, $field),
            );
        }

        return $values;
    }

    /**
     * @param iterable<object> $items elements of a child collection, current values
     *
     * @return list<array<string, mixed>>
     */
    public function normalizeChildren(iterable $items, EntityManagerInterface $entityManager): array
    {
        $normalized = [];
        foreach ($items as $item) {
            $normalized[] = $this->normalizeChild($item, $entityManager);
        }

        return $this->sort($normalized);
    }

    /**
     * @param iterable<Studio|Label> $items
     *
     * @return list<array<string, mixed>>
     */
    public function normalizeNamedItems(iterable $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            $normalized[] = ['name' => $item->name];
        }

        return $this->sort($normalized);
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    public function sort(array $items): array
    {
        usort($items, static fn (array $a, array $b): int => json_encode($a, \JSON_THROW_ON_ERROR) <=> json_encode($b, \JSON_THROW_ON_ERROR));

        return $items;
    }

    public function snapshot(Anime $anime, EntityManagerInterface $entityManager): AnimeSnapshot
    {
        $metadata = $entityManager->getClassMetadata($anime::class);

        $fields = [];
        foreach (self::ANIME_DOMAIN_FIELDS as $field) {
            // A subclass-only field (episodesCount) is absent on the other types.
            if ($field === 'type' || $metadata->hasField($field) || $metadata->hasAssociation($field)) {
                $fields[$field] = $this->normalizeAnimeField($anime, $field, $entityManager);
            }
        }

        foreach (self::ANIME_COLLECTION_FIELDS as $field) {
            /** @var iterable<object> $items */
            $items = $metadata->getFieldValue($anime, $field);
            $fields[$field] = isset(self::CHILD_CLASSES[$field])
                ? $this->normalizeChildren($items, $entityManager)
                : $this->normalizeNamedItems($items); // @phpstan-ignore argument.type
        }

        return new AnimeSnapshot($fields);
    }

    public function normalizeValue(mixed $value): mixed
    {
        return match (true) {
            $value === null, \is_scalar($value) => $value,
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(\DATE_ATOM),
            $value instanceof Rating => $value->value,
            $value instanceof Storage => $value->id,
            \is_array($value) => array_map($this->normalizeValue(...), $value),
            default => throw new \LogicException(\sprintf('Cannot normalize a value of type %s.', get_debug_type($value))),
        };
    }
}
