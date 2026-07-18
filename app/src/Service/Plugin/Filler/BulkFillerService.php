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

namespace App\Service\Plugin\Filler;

use AnimeDb\PluginContracts\AnimeType as ContractsAnimeType;
use AnimeDb\PluginContracts\FillerInterface;
use AnimeDb\PluginContracts\PluginAnimeData;
use App\Entity\Anime;
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\FillerRegistry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bulk fill-in scenario (issue #227): a Storage scan matched a top-level entry only through a
 * plugin search, with nothing in the local catalog to link it to (see
 * ScanStorageService::linkToChosenCandidate()'s plugin branch) — so a brand-new Anime is created
 * and immediately filled in with everything the plugin's FillerInterface can provide, instead of
 * the title-only placeholder that was the only option before this issue.
 *
 * "Bulk" here specifically means the create path: this service never touches an already
 * existing Anime, so the merge-vs-overwrite distinction PluginAnimeDataMerger enforces never
 * risks clobbering user data — every field it writes lands on a row that did not exist a moment
 * ago.
 * The one plugin used is whichever one produced the match in the first place — the issue's
 * "priority or explicitly user-chosen" plugin selection has no config surface to choose from yet
 * (no plugin management UI exists), so re-using the plugin that already found the title is the
 * only selection available today.
 */
final class BulkFillerService
{
    /** @var array<string, PluginAnimeData|null> findById() results cached for this service's lifetime, keyed by "pluginId:externalId" */
    private array $cache = [];

    public function __construct(
        private readonly FillerRegistry $fillerRegistry,
        private readonly PluginAnimeDataMerger $merger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return Anime|null null when no active filler is registered for $pluginId, or the plugin's
     *                    own find()/findById() could not resolve $name to anything — the caller
     *                    falls back to its own title-only placeholder in that case
     */
    public function fillNewFromPlugin(PluginId $pluginId, string $name): ?Anime
    {
        $filler = $this->fillerRegistry->findByPluginId($pluginId);
        if ($filler === null) {
            return null;
        }

        $resolved = $this->resolve($filler, $name);
        if ($resolved === null) {
            return null;
        }

        [$externalId, $data] = $resolved;

        $anime = $this->instantiate($data->type);
        $anime->setTitle($data->title)->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($pluginId, $externalId);

        // title/type are already applied above; cover/images stay out of the bulk create path —
        // downloading them needs the anime's own database id (see PluginAnimeDataMerger::applyCover()),
        // which this brand-new, not-yet-persisted Anime does not have yet.
        $fields = array_diff($filler->getFillableFields(), ['title', 'type', 'cover', 'images']);
        $this->merger->apply($anime, $data, $fields);

        $this->entityManager->persist($anime);

        return $anime;
    }

    /** @return array{0: string, 1: PluginAnimeData}|null */
    private function resolve(FillerInterface $filler, string $name): ?array
    {
        $candidates = $filler->find($name);
        if ($candidates === []) {
            return null;
        }

        $externalId = $candidates[0]->getExternalId();
        $data = $this->findById($filler, $externalId);

        return $data === null ? null : [$externalId, $data];
    }

    private function findById(FillerInterface $filler, string $externalId): ?PluginAnimeData
    {
        $cacheKey = $filler::class.':'.$externalId;
        if (\array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        return $this->cache[$cacheKey] = $filler->findById($externalId);
    }

    private function instantiate(?ContractsAnimeType $type): Anime
    {
        $class = $type === null ? TvAnime::class : AnimeType::from($type->value)->entityClass();

        return new $class();
    }
}
