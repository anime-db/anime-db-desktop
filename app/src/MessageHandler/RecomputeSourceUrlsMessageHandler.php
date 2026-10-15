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

namespace App\MessageHandler;

use App\Message\RecomputeSourceUrlsMessage;
use App\Service\Catalog\SourceUrlNormalizer;
use App\Service\Catalog\SourceUrlsFingerprintStore;
use App\Service\Catalog\SourceUrlsRecomputeScheduler;
use App\Service\Plugin\LinkRulesProvider;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Recomputes `anime_source.normalized_url` by one snapshot of the link rules (issue #1058).
 *
 * A bulk DBAL `UPDATE`, outside the ORM: this is not an edit of a record, so `date_update` and the
 * search index are left alone (the ORM path would go through AnimeAggregateTouchListener). Only rows
 * whose value changes are written, so a repeated run changes nothing. The fingerprint is written at
 * the end, for the snapshot that was used; if the rule set changed meanwhile it is not written and
 * the recompute is queued again.
 */
#[AsMessageHandler]
final class RecomputeSourceUrlsMessageHandler
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly LinkRulesProvider $rules,
        private readonly SourceUrlNormalizer $normalizer,
        private readonly SourceUrlsFingerprintStore $store,
        private readonly SourceUrlsRecomputeScheduler $scheduler,
    ) {
    }

    public function __invoke(RecomputeSourceUrlsMessage $message): void
    {
        $snapshot = $this->rules->snapshot();

        $lastId = 0;
        while (true) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, url, normalized_url FROM anime_source WHERE id > ? ORDER BY id LIMIT '.self::BATCH_SIZE,
                [$lastId],
            );
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                $normalized = $this->normalizer->normalizeWith((string) $row['url'], $snapshot->rules);
                if ($normalized !== $row['normalized_url']) {
                    $this->connection->executeStatement(
                        'UPDATE anime_source SET normalized_url = ? WHERE id = ?',
                        [$normalized, $lastId],
                    );
                }
            }
        }

        if ($this->rules->fingerprint() === $snapshot->fingerprint) {
            $this->store->set($snapshot->fingerprint);

            return;
        }

        $this->scheduler->schedule();
    }
}
