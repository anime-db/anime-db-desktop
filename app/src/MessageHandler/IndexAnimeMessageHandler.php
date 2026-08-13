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

use App\Entity\Anime;
use App\Message\IndexAnimeMessage;
use App\Service\Search\AnimeSearchIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A failure here (e.g. Meilisearch unreachable) is deliberately left to propagate: this
 * message already runs on the `async` transport's own retry_strategy (issue #97), so retrying
 * belongs to Messenger, not to a try/catch in this handler (see AnimeSearchIndexListener,
 * issue #197).
 */
#[AsMessageHandler]
final class IndexAnimeMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AnimeSearchIndexer $animeSearchIndexer,
    ) {
    }

    public function __invoke(IndexAnimeMessage $message): void
    {
        $anime = $this->entityManager->find(Anime::class, $message->animeId);
        if ($anime === null) {
            // Deleted (or the transaction that created it never committed) by the time this
            // message is processed — nothing left to index.
            return;
        }

        $this->animeSearchIndexer->index($anime);
    }
}
