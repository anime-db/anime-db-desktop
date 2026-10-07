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
use App\Entity\SeriesAnime;
use App\Entity\TvAnime;
use App\Repository\AnimeRepository;
use App\Repository\SyncReviewItemRepository;
use App\Repository\SyncTombstoneRepository;
use App\Service\Import\Exception\InvalidV1InstallationException;
use App\Service\Sync\SyncReviewService;
use App\Service\WsPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Replaces an empty catalog with the records of an AnimeDB v1 installation (issue #951): a
 * transformation through the ORM, never a swap of the database file.
 *
 * The whole insert is one transaction, so any failure leaves the catalog empty rather than half
 * imported. The frame is persist → flush → (covers, a later issue) → commit; covers are files
 * and need the ids of persisted rows, so the frame is fixed here, not left for that issue to
 * rewrite.
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

        return $this->entityManager->wrapInTransaction(fn (): V1ImportResult => $this->insert($records));
    }

    /** @param list<V1AnimeRecord> $records */
    private function insert(array $records): V1ImportResult
    {
        // Both tables outlive an emptied catalog and hold nothing the import could merge with.
        // Cleared before the records go in, so the review items raised below are not swept away.
        $this->tombstones->removeAll();
        $this->reviewItems->removeAll();

        $total = \count($records);
        $created = [];
        foreach ($records as $index => $record) {
            $anime = Anime::fromV1($record, $this->resolver);
            $this->entityManager->persist($anime);
            $created[] = [$anime, $record];

            $current = $index + 1;
            if ($current % self::PROGRESS_STEP === 0 || $current === $total) {
                $this->wsPublisher->publish('import.progress', ['phase' => 'v1', 'current' => $current, 'total' => $total]);
            }
        }

        $this->entityManager->flush();

        $needsAttention = 0;
        foreach ($created as [$anime]) {
            // A series with no end date may still be airing: it is not forced to Completed, and
            // the user is asked to look at it.
            if ($anime instanceof TvAnime && $anime->getDateEnd() === null && $anime->id !== null) {
                $this->reviewService->create(SyncReviewItemKind::NeedsCorrection, [
                    'anime_id' => $anime->id,
                    'anime_ids' => [$anime->id],
                    'message' => $this->translator->trans('import_v1.review_unknown_end_date', ['%title%' => $anime->getTitle()]),
                ]);
                ++$needsAttention;
            }
        }

        return $this->buildResult($created, $needsAttention);
    }

    /** @param list<array{0: Anime, 1: V1AnimeRecord}> $created */
    private function buildResult(array $created, int $needsAttention): V1ImportResult
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
            storagesCreated: $this->resolver->storagesCreated(),
            storagesUnavailable: $this->resolver->storagesUnavailable(),
            skippedStorageNames: $this->resolver->storagesSkipped(),
            endDatesSynthesized: $endDates,
            durationsCleared: $durations,
            episodesDroppedTitles: $episodesDropped,
            needsAttention: $needsAttention,
        );
    }
}
