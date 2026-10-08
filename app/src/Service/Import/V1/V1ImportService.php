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

use App\Entity\Anime;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\Enum\WatchStatus;
use App\Entity\Import\V1AnimeRecord;
use App\Entity\SeriesAnime;
use App\Entity\TvAnime;
use App\Repository\AnimeRepository;
use App\Repository\SyncReviewItemRepository;
use App\Repository\SyncTombstoneRepository;
use App\Service\Import\Exception\InvalidV1InstallationException;
use App\Service\Media\AnimeCoverStorage;
use App\Service\Media\ImageNormalizer;
use App\Service\Sync\SyncReviewService;
use App\Service\WsPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Replaces an empty catalog with the records of an AnimeDB v1 installation (issue #951): a
 * transformation through the ORM, never a swap of the database file.
 *
 * The whole insert is one transaction, so any failure leaves the catalog empty rather than half
 * imported. The frame is persist → flush → covers → commit; covers are files and need the ids of
 * persisted rows. A file is outside the transaction, so when anything fails after the first cover
 * was stored, the `media/{id}/` directories of this run are removed before the error goes on.
 * A killed process cannot clean up, and the leftovers of that case are accepted.
 *
 * Search indexes are left to fill themselves: the full-text triggers run in the same transaction,
 * and the Meilisearch listener queues its own message per created anime. Forcing indexing here
 * would be a second path that drifts from the usual one.
 */
final class V1ImportService
{
    private const int PROGRESS_STEP = 10;

    public function __construct(
        private readonly V1CatalogReader $reader,
        private readonly V1AnimeResolver $resolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly AnimeRepository $animeRepository,
        private readonly SyncTombstoneRepository $tombstones,
        private readonly SyncReviewItemRepository $reviewItems,
        private readonly SyncReviewService $reviewService,
        private readonly WsPublisher $wsPublisher,
        private readonly TranslatorInterface $translator,
        private readonly ImageNormalizer $imageNormalizer,
        private readonly AnimeCoverStorage $coverStorage,
    ) {
    }

    /** @throws InvalidV1InstallationException */
    public function import(string $installationDir): V1ImportResult
    {
        $records = $this->reader->read($installationDir);

        // Before any write: the command can be run from the CLI, whatever the interface offers.
        if ($this->animeRepository->countAll() > 0) {
            throw new InvalidV1InstallationException(InvalidV1InstallationException::REASON_CATALOG_NOT_EMPTY, [], 'The catalog is not empty: v1 can only be imported into an empty one');
        }

        $this->resolver->reset();

        $storedFor = [];
        try {
            return $this->entityManager->wrapInTransaction(function () use ($records, $installationDir, &$storedFor): V1ImportResult {
                return $this->insert($records, rtrim($installationDir, '/\\'), $storedFor);
            });
        } catch (\Throwable $e) {
            foreach ($storedFor as $animeId) {
                $this->coverStorage->discardDirectory($animeId);
            }

            throw $e;
        }
    }

    /**
     * @param list<V1AnimeRecord> $records
     * @param list<int>           $storedFor ids of the entries whose cover directory this run may have created
     */
    private function insert(array $records, string $installationDir, array &$storedFor): V1ImportResult
    {
        // Both tables outlive an emptied catalog and hold nothing the import could merge with.
        // Cleared before the records go in, so the review items raised below are not swept away.
        $this->tombstones->removeAll();
        $this->reviewItems->removeAll();

        $total = \count($records);
        $created = [];
        foreach ($records as $index => $record) {
            try {
                $anime = Anime::fromV1($record, $this->resolver);
            } catch (\InvalidArgumentException|\DomainException $e) {
                // All or nothing: the transaction rolls back, and the one record that broke an
                // invariant is named so the user can fix it in v1 and run the import again.
                throw new InvalidV1InstallationException(InvalidV1InstallationException::REASON_INVALID_RECORD, ['%id%' => $record->id, '%title%' => $record->title], \sprintf('Record %d of the v1 catalog cannot be imported: %s', $record->id, $e->getMessage()), $e);
            }
            $this->entityManager->persist($anime);
            $created[] = [$anime, $record];

            $current = $index + 1;
            if ($current % self::PROGRESS_STEP === 0 || $current === $total) {
                $this->wsPublisher->publish('import.progress', ['phase' => 'v1', 'current' => $current, 'total' => $total]);
            }
        }

        $this->entityManager->flush();

        [$coversImported, $coversMissing] = $this->importCovers($created, $installationDir, $storedFor);

        $needsAttention = 0;
        $downgraded = 0;
        foreach ($created as [$anime, $record]) {
            if ($anime->id === null) {
                continue;
            }

            // A "Completed" the release dates cannot back up is demoted to Plan/Watching: whatever
            // the type, the user is told, since a watched title must not silently become unwatched.
            // A series with no end date may still be airing: it is not forced to Completed either.
            $wasDowngraded = $this->resolver->resolveWatchStatus($record) === WatchStatus::Completed
                && $anime->getWatchStatus() !== WatchStatus::Completed;
            $unknownEnd = $anime instanceof TvAnime && $anime->getDateEnd() === null;
            if (!$wasDowngraded && !$unknownEnd) {
                continue;
            }

            $message = $wasDowngraded
                ? $this->translator->trans('import_v1.review_status_downgraded', ['%title%' => $anime->getTitle(), '%status%' => $anime->getWatchStatus()->value])
                : $this->translator->trans('import_v1.review_unknown_end_date', ['%title%' => $anime->getTitle()]);
            $this->reviewService->create(SyncReviewItemKind::NeedsCorrection, [
                'anime_id' => $anime->id,
                'anime_ids' => [$anime->id],
                'message' => $message,
            ]);
            ++$needsAttention;
            if ($wasDowngraded) {
                ++$downgraded;
            }
        }

        return $this->buildResult($created, $needsAttention, $downgraded, $coversImported, $coversMissing);
    }

    /**
     * A cover that is absent, unreadable or not a picture is a count in the report, not an error.
     *
     * @param list<array{0: Anime, 1: V1AnimeRecord}> $created
     * @param list<int>                               $storedFor
     *
     * @return array{0: int, 1: int} imported, missing
     */
    private function importCovers(array $created, string $installationDir, array &$storedFor): array
    {
        $mediaDir = V1CatalogReader::mediaDir($installationDir);
        $imported = $missing = 0;

        foreach ($created as [$anime, $record]) {
            $webp = $mediaDir !== null && $record->cover !== null ? $this->normalizeCover($mediaDir, $record->cover) : null;
            if ($webp === null || $anime->id === null) {
                ++$missing;

                continue;
            }

            $storedFor[] = $anime->id;
            $anime->setCover($this->coverStorage->store($anime, $webp));
            ++$imported;
        }

        if ($imported > 0) {
            $this->entityManager->flush();
        }

        return [$imported, $missing];
    }

    private function normalizeCover(string $mediaDir, string $cover): ?string
    {
        // The value is a relative path inside web/media/; anything that climbs out of it is not a cover.
        if ($cover === '' || str_contains($cover, "\0") || preg_match('~(^|[/\\\\])\.\.([/\\\\]|$)|^[/\\\\]|^[A-Za-z]:~', $cover) === 1) {
            return null;
        }

        $path = $mediaDir.'/'.$cover;
        if (!is_file($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        return $this->imageNormalizer->normalize($bytes);
    }

    /** @param list<array{0: Anime, 1: V1AnimeRecord}> $created */
    private function buildResult(array $created, int $needsAttention, int $downgraded, int $coversImported, int $coversMissing): V1ImportResult
    {
        $fromLabel = $byDefault = $ja = $ru = $none = $sources = $descriptions = 0;
        $mapped = $dropped = $unmapped = $endDates = $durations = 0;
        $studios = [];
        $labels = [];
        $unmappedNames = [];
        $episodesDropped = [];

        foreach ($created as [$anime, $record]) {
            $this->resolver->hasExplicitWatchStatus($record) ? ++$fromLabel : ++$byDefault;

            foreach ($anime->getNames() as $name) {
                match ($name->locale) {
                    'ja' => ++$ja,
                    'ru' => ++$ru,
                    default => ++$none,
                };
            }
            $sources += $anime->getSources()->count();
            $descriptions += $anime->getDescriptions()->count();
            foreach ($anime->getStudios() as $studio) {
                $studios[spl_object_id($studio)] = true;
            }
            foreach ($anime->getLabels() as $label) {
                $labels[spl_object_id($label)] = true;
            }

            $genres = $this->resolver->resolveGenres($record);
            $mapped += \count($genres->genres) + \count($genres->themes) + ($genres->demographic !== null ? 1 : 0);
            $dropped += \count($genres->droppedByDesign);
            $unmapped += \count($genres->unmapped);
            foreach ($genres->unmapped as $name) {
                $unmappedNames[$name] = true;
            }

            if ($record->dateEnd === null && $anime->getDateEnd() !== null) {
                ++$endDates;
            }
            if ($record->duration !== null && $record->duration <= 0) {
                ++$durations;
            }
            if (!$anime instanceof SeriesAnime && $record->episodesNumber !== null && $record->episodesNumber > 1) {
                $episodesDropped[] = $record->title;
            }
        }

        return new V1ImportResult(
            animeCreated: \count($created),
            withStatusFromLabel: $fromLabel,
            withDefaultStatus: $byDefault,
            namesJapanese: $ja,
            namesRussian: $ru,
            namesUnknownLocale: $none,
            sources: $sources,
            descriptions: $descriptions,
            studios: \count($studios),
            labels: \count($labels),
            genresMapped: $mapped,
            genresDroppedByDesign: $dropped,
            genresUnmapped: $unmapped,
            unmappedGenreNames: array_map('strval', array_keys($unmappedNames)),
            coversImported: $coversImported,
            coversMissing: $coversMissing,
            storagesCreated: $this->resolver->storagesCreated(),
            storagesUnavailable: $this->resolver->storagesUnavailable(),
            skippedStorageNames: $this->resolver->storagesSkipped(),
            endDatesSynthesized: $endDates,
            durationsCleared: $durations,
            episodesDroppedTitles: $episodesDropped,
            needsAttention: $needsAttention,
            statusesDowngraded: $downgraded,
        );
    }
}
