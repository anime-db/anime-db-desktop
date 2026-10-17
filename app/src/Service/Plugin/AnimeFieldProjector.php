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

namespace App\Service\Plugin;

use AnimeDb\PluginContracts\Catalog\AnimeField;
use AnimeDb\PluginContracts\Catalog\FieldChange as ContractFieldChange;
use AnimeDb\PluginContracts\Model\AnimeName as ContractAnimeName;
use AnimeDb\PluginContracts\Model\AnimeType as ContractAnimeType;
use AnimeDb\PluginContracts\Model\Demographic as ContractDemographic;
use AnimeDb\PluginContracts\Model\GenreCode as ContractGenreCode;
use AnimeDb\PluginContracts\Model\NameRole as ContractNameRole;
use AnimeDb\PluginContracts\Model\ThemeCode as ContractThemeCode;
use App\Event\Catalog\AnimeSnapshot;
use App\Event\Catalog\FieldChange;

/**
 * The one place that decides what plugins see of a catalog record (issue #1075): maps the fields of
 * the internal catalog events (the normalized form of {@see \App\DomainEvent\Catalog\AnimeSnapshotNormalizer})
 * onto the {@see AnimeField} vocabulary, in the value shapes the table of {@see AnimeField} gives.
 * A field outside the vocabulary (watch status, watched episodes, rating, notes, labels, storage and
 * path, external ids, gallery) has no entry here and so never reaches a plugin.
 *
 * Changes are filtered after the projection, not before: an internal change can leave the projected
 * value as it was (only the source of a description changed, countries were reordered).
 */
final class AnimeFieldProjector
{
    /** Internal field => vocabulary field. */
    private const array FIELDS = [
        'title' => AnimeField::Title,
        'names' => AnimeField::AlternativeNames,
        'type' => AnimeField::Type,
        'demographic' => AnimeField::Demographic,
        'genres' => AnimeField::Genres,
        'themes' => AnimeField::Themes,
        'episodesCount' => AnimeField::EpisodesCount,
        'sources' => AnimeField::Sources,
        'descriptions' => AnimeField::Description,
        'datePremiere' => AnimeField::DatePremiere,
        'dateEnd' => AnimeField::DateEnd,
        'durationMinutes' => AnimeField::DurationMinutes,
        'studios' => AnimeField::Studios,
        'countries' => AnimeField::Countries,
        'cover' => AnimeField::Cover,
    ];

    /**
     * Only the vocabulary fields whose projected value differs before and after.
     *
     * @param array<string, FieldChange> $changes
     *
     * @return array<value-of<AnimeField>, ContractFieldChange>
     */
    public function projectChanges(array $changes): array
    {
        $projected = [];
        foreach (self::FIELDS as $internal => $field) {
            if (!isset($changes[$internal])) {
                continue;
            }

            $before = $this->project($field, $changes[$internal]->before);
            $after = $this->project($field, $changes[$internal]->after);
            if ($this->plain($before) !== $this->plain($after)) {
                $projected[$field->value] = new ContractFieldChange($before, $after);
            }
        }

        return $projected;
    }

    /**
     * Every vocabulary field of a snapshot.
     *
     * @return array<value-of<AnimeField>, mixed>
     */
    public function projectSnapshot(AnimeSnapshot $snapshot): array
    {
        $values = [];
        foreach (self::FIELDS as $internal => $field) {
            $values[$field->value] = $this->project($field, $snapshot->fields[$internal] ?? null);
        }

        return $values;
    }

    private function project(AnimeField $field, mixed $raw): mixed
    {
        return match ($field) {
            AnimeField::Title => \is_string($raw) ? $raw : '',
            AnimeField::AlternativeNames => $this->names($raw),
            AnimeField::Type => \is_string($raw) ? ContractAnimeType::tryFrom($raw) : null,
            AnimeField::Demographic => \is_string($raw) ? ContractDemographic::tryFrom($raw) : null,
            AnimeField::Genres => $this->codes($raw, ContractGenreCode::class),
            AnimeField::Themes => $this->codes($raw, ContractThemeCode::class),
            AnimeField::EpisodesCount, AnimeField::DurationMinutes => \is_int($raw) ? $raw : null,
            AnimeField::Sources => $this->strings($raw, 'url'),
            AnimeField::Studios => $this->strings($raw, 'name'),
            AnimeField::Description => $this->descriptions($raw),
            AnimeField::DatePremiere, AnimeField::DateEnd => $this->date($raw),
            AnimeField::Countries => $this->countries($raw),
            AnimeField::Cover => \is_string($raw) && basename(str_replace('\\', '/', $raw)) !== '' ? basename(str_replace('\\', '/', $raw)) : null,
        };
    }

    /** @return list<ContractAnimeName> */
    private function names(mixed $raw): array
    {
        $names = [];
        foreach (\is_array($raw) ? $raw : [] as $item) {
            $role = \is_array($item) && \is_string($item['role'] ?? null) ? ContractNameRole::tryFrom($item['role']) : null;
            if ($role === null || !\is_string($item['name'] ?? null)) {
                continue;
            }

            $names[] = new ContractAnimeName($item['name'], \is_string($item['locale'] ?? null) ? $item['locale'] : null, $role);
        }

        usort($names, static fn (ContractAnimeName $a, ContractAnimeName $b): int => [$a->locale ?? '', $a->role->value, $a->name] <=> [$b->locale ?? '', $b->role->value, $b->name]);

        return $names;
    }

    /**
     * @template T of ContractGenreCode|ContractThemeCode
     *
     * @param class-string<T> $enum
     *
     * @return list<T>
     */
    private function codes(mixed $raw, string $enum): array
    {
        $codes = [];
        foreach (\is_array($raw) ? $raw : [] as $item) {
            $code = \is_array($item) && \is_string($item['code'] ?? null) ? $enum::tryFrom($item['code']) : null;
            if ($code !== null) {
                $codes[$code->value] = $code;
            }
        }
        ksort($codes, \SORT_STRING);

        return array_values($codes);
    }

    /** @return list<string> */
    private function strings(mixed $raw, string $key): array
    {
        $values = [];
        foreach (\is_array($raw) ? $raw : [] as $item) {
            if (\is_array($item) && \is_string($item[$key] ?? null)) {
                $values[] = $item[$key];
            }
        }
        sort($values, \SORT_STRING);

        return $values;
    }

    /** @return array<string, string> locale => description, the source being no part of the value */
    private function descriptions(mixed $raw): array
    {
        $descriptions = [];
        foreach (\is_array($raw) ? $raw : [] as $item) {
            if (\is_array($item) && \is_string($item['locale'] ?? null) && \is_string($item['description'] ?? null)) {
                $descriptions[$item['locale']] ??= $item['description'];
            }
        }
        ksort($descriptions, \SORT_STRING);

        return $descriptions;
    }

    private function date(mixed $raw): ?\DateTimeImmutable
    {
        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable(
                (new \DateTimeImmutable($raw))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d').'T00:00:00+00:00',
            );
        } catch (\Exception) {
            return null;
        }
    }

    /** @return list<string> */
    private function countries(mixed $raw): array
    {
        $countries = [];
        foreach (\is_array($raw) ? $raw : [] as $country) {
            if (\is_string($country) && $country !== '') {
                $countries[strtoupper($country)] = strtoupper($country);
            }
        }
        sort($countries, \SORT_STRING);

        return $countries;
    }

    /** A strictly comparable form of a projected value: a loose `==` would equate "1e3" and "1000". */
    private function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \DateTimeImmutable => $value->format(\DATE_ATOM),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof ContractAnimeName => [$value->name, $value->locale, $value->role->value],
            \is_array($value) => array_map($this->plain(...), $value),
            default => $value,
        };
    }
}
