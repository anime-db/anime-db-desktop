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
use App\Entity\AnimeImage;
use App\Message\DownloadAnimeMediaMessage;
use App\Service\Plugin\Filler\PluginMediaDownloaderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Unlike IndexAnimeMessageHandler/PushSyncMessageHandler, a download or normalization failure
 * here is never allowed to propagate (issue #508): {@see PluginMediaDownloaderInterface::download()}
 * already turns every such failure (unreachable host, SSRF rejection, failed WebP normalization)
 * into a logged `null` return, which this handler treats as "nothing to apply" rather than an
 * error — from the `media` transport's point of view the message was handled successfully, no
 * retry, no `failure_transport` (deliberately unconfigured, see messenger.yaml). The anime this
 * message targets being gone by the time it is processed is handled the same way: that is a
 * legal race between dispatch and processing, not a failure, so this handler returns silently
 * rather than throwing — throwing would burn all retries and log noise over an unexceptional
 * outcome. Nothing in this handler is expected to throw in normal operation.
 */
#[AsMessageHandler]
final class DownloadAnimeMediaMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PluginMediaDownloaderInterface $mediaDownloader,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(DownloadAnimeMediaMessage $message): void
    {
        $anime = $this->entityManager->find(Anime::class, $message->animeId);
        if ($anime === null) {
            // Deleted between BulkFillerService::build() dispatching this message and it being
            // processed — nothing left to attach the download to.
            return;
        }

        // The entry can be deleted (AnimeDeleteService removes its media directory right after) while the
        // image is being fetched: ask again right before the file is written, and once more before it is
        // attached, so neither a media directory nor a gallery row is created for a deleted entry.
        $stillExists = fn (): bool => $this->animeExists($message->animeId);

        $filename = $this->mediaDownloader->download($message->animeId, $message->url, $stillExists);
        if (!$stillExists()) {
            return;
        }

        if ($filename === null) {
            $this->logger->warning('Discarding a queued anime media download: the URL could not be downloaded or normalized into a WebP image.', [
                'animeId' => $message->animeId,
                'url' => $message->url,
            ]);

            return;
        }

        if ($message->isCover) {
            $anime->setCover($filename);
        } else {
            $this->addImageIfNew($anime, $filename);
        }

        $this->entityManager->flush();
    }

    private function animeExists(int $animeId): bool
    {
        return $this->entityManager->getConnection()->fetchOne('SELECT 1 FROM anime WHERE id = ?', [$animeId]) !== false;
    }

    /**
     * Anime::addImage() does not dedupe by itself — the same URL processed twice (e.g. a
     * redelivered message) hashes to the same filename ({@see \App\Service\Plugin\Filler\HttpPluginMediaDownloader}),
     * so without this check it would show up twice in the gallery. Same dedup-by-filename rule
     * PluginAnimeDataMerger::applyImages() applies for the point fill-in scenario.
     */
    private function addImageIfNew(Anime $anime, string $filename): void
    {
        $existing = array_map(static fn (AnimeImage $image): string => $image->source, $anime->getImages()->toArray());
        if (!\in_array($filename, $existing, true)) {
            $anime->addImage($filename);
        }
    }
}
