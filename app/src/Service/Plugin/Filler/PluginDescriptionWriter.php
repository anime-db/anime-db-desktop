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

namespace App\Service\Plugin\Filler;

use App\DomainEvent\Catalog\AnimeEventRecorder;
use App\Entity\Anime;
use App\Entity\AnimeDescription;
use App\Entity\DescriptionText;
use App\Entity\ValueObject\PluginId;
use App\Service\AppSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one write policy for a description text that came from a plugin (issue #1068). For a plugin
 * P that returned text T for locale L, the current row (text, source) is judged in this order:
 *
 *  1. source is `manual`            - never touched, even when its text is empty;
 *  2. no row / empty text           - T is written, source = P;
 *  3. source NULL, text equals T    - only source = P is set (attribution; the text stays as stored);
 *  4. source NULL, text differs     - never touched: it may be hand-written text of unknown origin;
 *  5. no source priority configured - not touched;
 *  6. source = P                    - the text is updated (the source refreshed its own text);
 *  7. P ranked strictly above source - the text is replaced, source = P;
 *  8. anything else                 - not touched.
 *
 * The priority is {@see AppSettingsProvider::getDescriptionSourcePriority()}: one ordered list of
 * plugin ids for all locales, configured only when it is non-empty. A plugin outside the list is
 * ranked below every listed one, and two unranked plugins never replace each other (rule 7 needs
 * P itself to be ranked).
 *
 * Texts are compared in their {@see DescriptionText::normalize()} form and T is stored normalized.
 *
 * The decision is made on the row as the entity holds it (the snapshot), but a persisted row is
 * written with a conditional UPDATE on that snapshot's source and text, and a missing locale with
 * `INSERT ... ON CONFLICT DO NOTHING`: another writer (the edit form, a concurrent fill) that got
 * in between turns this write into a no-op instead of overwriting it, and a lost insert race never
 * closes the EntityManager.
 *
 * Both writes bypass the UnitOfWork, so the successful ones record `AnimeChanged` of the
 * `descriptions` field through {@see AnimeEventRecorder}; a write that changed no row records nothing.
 */
class PluginDescriptionWriter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?AppSettingsProvider $settings = null,
        private readonly ?AnimeEventRecorder $recorder = null,
    ) {
    }

    public function write(Anime $anime, PluginId $pluginId, string $locale, string $text): void
    {
        $text = DescriptionText::normalize($text);
        if ($text === '') {
            return;
        }
        $source = (string) $pluginId;

        $row = null;
        foreach ($anime->getDescriptions() as $description) {
            if ($description->locale === $locale) {
                $row = $description;
                break;
            }
        }

        if ($row === null) {
            $this->insert($anime, $locale, $text, $source);

            return;
        }

        if ($row->source === AnimeDescription::SOURCE_MANUAL) {
            return;
        }

        $current = DescriptionText::normalize($row->description);
        if ($current === '') {
            $this->update($anime, $row, $text, $source);
        } elseif ($row->source === null && $current === $text) {
            $this->update($anime, $row, $row->description, $source);
        } elseif ($row->source !== null && $current !== $text && $this->mayReplace($row->source, $source)) {
            $this->update($anime, $row, $text, $source);
        }
    }

    /**
     * Rules 5-8 for a row with a known source that is not manual and has text.
     */
    private function mayReplace(string $rowSource, string $source): bool
    {
        $priority = $this->settings?->getDescriptionSourcePriority() ?? [];
        if ($priority === []) {
            return false;
        }
        if ($rowSource === $source) {
            return true;
        }

        $rank = array_search($source, $priority, true);
        if ($rank === false) {
            return false;
        }
        $rowRank = array_search($rowSource, $priority, true);

        return $rowRank === false || $rank < $rowRank;
    }

    private function insert(Anime $anime, string $locale, string $text, string $source): void
    {
        if ($anime->id === null) {
            $anime->setDescription($locale, $text, $source);

            return;
        }

        $animeId = $anime->id;
        $before = $this->recorder?->children($anime, 'descriptions');
        $connection = $this->entityManager->getConnection();
        $inserted = $connection->executeStatement(
            'INSERT INTO anime_description (anime_id, locale, description, source) VALUES (?, ?, ?, ?) ON CONFLICT (anime_id, locale) DO NOTHING',
            [$anime->id, $locale, $text, $source],
        );
        if ($inserted === 0) {
            return;
        }

        $id = (int) $connection->lastInsertId();
        $entity = $this->entityManager->find(AnimeDescription::class, $id);
        if ($entity !== null) {
            $anime->attachPersistedDescription($entity);
        }
        // The row bypassed the UnitOfWork, so AnimeAggregateTouchListener cannot see it (issue #888).
        $anime->touchDateUpdate();

        if ($this->recorder !== null && $before !== null) {
            $this->recorder->recordChanged($animeId, [
                'descriptions' => [$before, $this->recorder->sort([...$before, ['locale' => $locale, 'description' => $text, 'source' => $source]])],
            ]);
        }
    }

    private function update(Anime $anime, AnimeDescription $row, string $text, string $source): void
    {
        if ($anime->id === null || $row->id === null) {
            $row->description = $text;
            $row->source = $source;

            return;
        }

        $animeId = $anime->id;
        $before = $this->recorder?->children($anime, 'descriptions');
        $updated = $this->entityManager->getConnection()->executeStatement(
            'UPDATE anime_description SET description = ?, source = ? WHERE id = ? AND source IS ? AND description = ?',
            [$text, $source, $row->id, $row->source, $row->description],
        );
        if ($updated === 0) {
            return;
        }

        // Keep the managed entity in line with the row so the next flush() sees no change.
        $row->description = $text;
        $row->source = $source;
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $oid = spl_object_id($row);
        $unitOfWork->setOriginalEntityProperty($oid, 'description', $text);
        $unitOfWork->setOriginalEntityProperty($oid, 'source', $source);
        // The change set is cleared above, so the listener cannot see it (issue #888).
        $anime->touchDateUpdate();

        if ($this->recorder !== null && $before !== null) {
            $this->recorder->recordChanged($animeId, [
                'descriptions' => [$before, $this->recorder->children($anime, 'descriptions')],
            ]);
        }
    }
}
