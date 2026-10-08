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

namespace App\Service\Import\V1;

use App\Entity\Enum\AnimeType;
use App\Entity\Enum\Demographic;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\ThemeCode;
use App\Entity\Enum\WatchStatus;
use App\Entity\Exception\InvalidNameException;
use App\Entity\Exception\InvalidPathException;
use App\Entity\Import\V1AlternativeName;
use App\Entity\Import\V1AnimeRecord;
use App\Entity\Import\V1AnimeResolverInterface;
use App\Entity\Import\V1GenreSet;
use App\Entity\Import\V1StorageRecord;
use App\Entity\Label;
use App\Entity\NameNormalizer;
use App\Entity\Storage;
use App\Entity\Studio;
use App\Repository\LabelRepository;
use App\Repository\StorageRepository;
use App\Repository\StudioRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Knows the AnimeDB v1 vocabulary: the type names, the Russian status labels, the genre
 * dictionary of MAL before its reorganisation and the script of an alternative name — and owns
 * the reference rows (labels, studios, storages), which are looked up by name (storages: by path, or by name when there is none)
 * and created only when missing, so a catalog that already has them gets no second copy.
 *
 * Holds per-import state (the reference rows it has created and the storage counters), so
 * {@see self::reset()} starts every import.
 *
 * @internal
 */
final class V1AnimeResolver implements V1AnimeResolverInterface
{
    /** Status kept for a record whose v1 labels name none: a collection of downloads is rarely unwatched. */
    public const WatchStatus DEFAULT_WATCH_STATUS = WatchStatus::Completed;

    private const array TYPES = [
        'tv' => AnimeType::Tv,
        'feature' => AnimeType::Movie,
        'featurette' => AnimeType::Movie,
        'ova' => AnimeType::Ova,
        'ona' => AnimeType::Ona,
        'special' => AnimeType::Special,
        'music' => AnimeType::Music,
    ];

    /** v1 stored the status as an ordinary label; these names are statuses, the rest are labels. */
    private const array STATUS_LABELS = [
        'запланировано' => WatchStatus::Plan,
        'смотрю' => WatchStatus::Watching,
        'просмотрено' => WatchStatus::Completed,
        'отложено' => WatchStatus::OnHold,
        'брошено' => WatchStatus::Dropped,
    ];

    /** v1 genres whose normalised name is not in the current MAL taxonomy under the same name: renamed, re-spelled or merged on MAL's side. */
    private const array GENRE_EXCEPTIONS = [
        'history' => ThemeCode::Historical,
        'thriller' => GenreCode::Suspense,
        'sport' => GenreCode::Sports,
        'demons' => ThemeCode::Mythology,
        'mahoeshoujo' => ThemeCode::MahouShoujo,
        'shoujoai' => GenreCode::GirlsLove,
        'shounenai' => GenreCode::BoysLove,
        'war' => ThemeCode::Military,
        'cars' => ThemeCode::Racing,
        'game' => ThemeCode::StrategyGame,
        'genderbender' => ThemeCode::MagicalSexShift,
    ];

    /** The 18+ axis is left out of the v2 vocabularies on purpose; this is not a gap in the mapping. */
    private const array EXPLICIT_GENRES = ['ecchi', 'erotica', 'hentai', 'yuri', 'yaoi'];

    private const string JAPANESE_PATTERN = '/[\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{FF00}-\x{FFEF}]/u';
    private const string CYRILLIC_PATTERN = '/\p{Cyrillic}/u';

    /** @var array<string, Label> */
    private array $labels = [];

    /** @var array<string, Studio> */
    private array $studios = [];

    /** @var array<string, Storage>|null by {@see storageKey()} */
    private ?array $storages = null;

    private int $storagesCreated = 0;
    private int $storagesUnavailable = 0;

    /** @var list<string> */
    private array $storagesSkipped = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LabelRepository $labelRepository,
        private readonly StudioRepository $studioRepository,
        private readonly StorageRepository $storageRepository,
    ) {
    }

    public function reset(): void
    {
        $this->labels = [];
        $this->studios = [];
        $this->storages = null;
        $this->storagesCreated = 0;
        $this->storagesUnavailable = 0;
        $this->storagesSkipped = [];
    }

    public function storagesCreated(): int
    {
        return $this->storagesCreated;
    }

    /** Created storages whose path does not exist on this machine. */
    public function storagesUnavailable(): int
    {
        return $this->storagesUnavailable;
    }

    /** @return list<string> names of the storages left out: no path, or a path a storage refuses */
    public function storagesSkipped(): array
    {
        return $this->storagesSkipped;
    }

    public function resolveType(V1AnimeRecord $record): AnimeType
    {
        return self::TYPES[strtolower(trim($record->type ?? ''))] ?? AnimeType::Tv;
    }

    public function hasExplicitWatchStatus(V1AnimeRecord $record): bool
    {
        foreach ($record->labels as $name) {
            if (isset(self::STATUS_LABELS[mb_strtolower(trim($name))])) {
                return true;
            }
        }

        return false;
    }

    public function resolveWatchStatus(V1AnimeRecord $record): WatchStatus
    {
        foreach ($record->labels as $name) {
            $status = self::STATUS_LABELS[mb_strtolower(trim($name))] ?? null;
            if ($status !== null) {
                return $status;
            }
        }

        return self::DEFAULT_WATCH_STATUS;
    }

    public function resolveLabels(V1AnimeRecord $record): array
    {
        $labels = [];
        foreach ($record->labels as $name) {
            $name = trim($name);
            // The split happens before the lookup: no "Просмотрено" label may reach the catalog.
            if ($name === '' || isset(self::STATUS_LABELS[mb_strtolower($name)])) {
                continue;
            }

            $labels[mb_strtolower($name)] ??= $this->findOrCreateLabel($name);
        }

        return array_values($labels);
    }

    public function resolveStudios(V1AnimeRecord $record): array
    {
        $name = trim($record->studio ?? '');
        if ($name === '') {
            return [];
        }

        return [$this->findOrCreateStudio($name)];
    }

    public function resolveStorage(V1AnimeRecord $record): ?Storage
    {
        $v1 = $record->storage;
        if ($v1 === null) {
            return null;
        }

        $path = trim($v1->path ?? '');
        // v1 types match StorageType one to one; the fallback only guards against a hand-edited database
        $type = StorageType::tryFrom(strtolower(trim($v1->type ?? ''))) ?? StorageType::Folder;
        $name = trim($v1->name) !== '' ? $v1->name : $path;

        try {
            $storage = new Storage($name, $path !== '' ? $path : null, $type);
        } catch (InvalidPathException|InvalidNameException) {
            $this->skipStorage($v1);

            return null;
        }

        $key = $this->storageKey($storage);
        $storages = $this->storagesByKey();
        if (isset($storages[$key])) {
            return $storages[$key];
        }

        $this->entityManager->persist($storage);
        $this->storages[$key] = $storage;
        ++$this->storagesCreated;
        if ($storage->getPath() !== null && !file_exists($storage->getPath())) {
            ++$this->storagesUnavailable;
        }

        return $storage;
    }

    public function resolveGenres(V1AnimeRecord $record): V1GenreSet
    {
        $genres = [];
        $themes = [];
        $demographics = [];
        $dropped = [];
        $unmapped = [];

        foreach ($record->genres as $name) {
            $key = self::normalizeGenreName($name);
            if ($key === '') {
                continue;
            }

            if (\in_array($key, self::EXPLICIT_GENRES, true)) {
                $dropped[$key] = trim($name);

                continue;
            }

            $code = self::GENRE_EXCEPTIONS[$key] ?? self::matchTaxonomy($key);
            match (true) {
                $code instanceof GenreCode => $genres[$code->value] = $code,
                $code instanceof ThemeCode => $themes[$code->value] = $code,
                $code instanceof Demographic => $demographics[$code->value] = [$code, trim($name)],
                default => $unmapped[$key] = trim($name),
            };
        }

        $demographic = null;
        $extra = [];
        foreach ($demographics as [$value, $name]) {
            if ($demographic === null) {
                $demographic = $value;
            } else {
                $extra[] = $name;
            }
        }

        return new V1GenreSet(
            array_values($genres),
            array_values($themes),
            $demographic,
            $extra,
            array_values($dropped),
            array_values($unmapped),
        );
    }

    public function resolveNames(V1AnimeRecord $record): array
    {
        $names = [];
        foreach ($record->names as $raw) {
            $name = trim($raw);
            if ($name === '') {
                continue;
            }

            $locale = self::detectLocale($name);
            $names[NameNormalizer::normalize($name)."\0".$locale] ??= new V1AlternativeName($name, $locale);
        }

        return array_values($names);
    }

    public function resolveNotes(V1AnimeRecord $record): ?string
    {
        $parts = [];
        foreach ([$record->episodes, $record->translate, $record->fileInfo] as $part) {
            $part = trim($part ?? '');
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    /**
     * Only the language, never the role. Japanese is checked first: a mixed line such as
     * "BECK　ベック" holds Latin letters too, but its Japanese writing decides. Latin text stays
     * unknown on purpose — the original's romaji and an English title look the same to a machine.
     */
    private static function detectLocale(string $name): ?string
    {
        if (preg_match(self::JAPANESE_PATTERN, $name) === 1) {
            return 'ja';
        }

        if (preg_match(self::CYRILLIC_PATTERN, $name) === 1) {
            return 'ru';
        }

        return null;
    }

    private static function normalizeGenreName(string $name): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $name));
    }

    private static function matchTaxonomy(string $key): GenreCode|ThemeCode|Demographic|null
    {
        foreach ([...GenreCode::cases(), ...ThemeCode::cases(), ...Demographic::cases()] as $case) {
            if (self::normalizeGenreName($case->value) === $key) {
                return $case;
            }
        }

        return null;
    }

    private function findOrCreateLabel(string $name): Label
    {
        $key = mb_strtolower($name);
        if (isset($this->labels[$key])) {
            return $this->labels[$key];
        }

        $label = $this->labelRepository->findOneByName($name);
        if ($label === null) {
            $label = new Label($name);
            $this->entityManager->persist($label);
        }

        return $this->labels[$key] = $label;
    }

    private function findOrCreateStudio(string $name): Studio
    {
        $key = mb_strtolower($name);
        if (isset($this->studios[$key])) {
            return $this->studios[$key];
        }

        $studio = $this->studioRepository->findOneByName($name);
        if ($studio === null) {
            $studio = new Studio();
            $studio->rename($name);
            $this->entityManager->persist($studio);
        }

        return $this->studios[$key] = $studio;
    }

    /** Storages with a path are the same by path, the ones without (cassettes, discs) by name. */
    private function storageKey(Storage $storage): string
    {
        $path = $storage->getPath();

        return $path !== null ? 'path:'.$path : 'name:'.mb_strtolower($storage->getName());
    }

    /** @return array<string, Storage> */
    private function storagesByKey(): array
    {
        if ($this->storages === null) {
            $this->storages = [];
            foreach ($this->storageRepository->findAllOrderedByName() as $storage) {
                $this->storages[$this->storageKey($storage)] = $storage;
            }
        }

        return $this->storages;
    }

    private function skipStorage(V1StorageRecord $storage): void
    {
        if (!\in_array($storage->name, $this->storagesSkipped, true)) {
            $this->storagesSkipped[] = $storage->name;
        }
    }
}
