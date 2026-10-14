<?php

declare(strict_types=1);

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

namespace App\MessageHandler;

use App\Entity\Anime;
use App\Message\SaturateAnimeMessage;
use App\Service\JobLock\JobLockService;
use App\Service\Plugin\Filler\SaturationQueue;
use App\Service\Plugin\Filler\SaturationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs {@see SaturationService} for one record (issue #1049). Never throws: the transports have no
 * retry strategy of their own to rely on, and a retry would walk every plugin again. A record
 * already being saturated by the other consumer (lock `saturate:<id>`) is re-queued into the same
 * transport 30 s later rather than dropped, so links added after that run started are not lost.
 */
#[AsMessageHandler]
final class SaturateAnimeMessageHandler
{
    public const REQUEUE_DELAY_SECONDS = 30;

    public function __construct(
        private readonly SaturationService $saturation,
        private readonly SaturationQueue $queue,
        private readonly JobLockService $jobLockService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SaturateAnimeMessage $message): void
    {
        $key = SaturateAnimeMessage::jobKey($message->animeId);

        try {
            if (!$this->entityManager->isOpen()) {
                $this->logger->warning('EntityManager is closed, dropping saturation of anime #{animeId}.', ['animeId' => $message->animeId]);

                return;
            }

            if (!$this->jobLockService->acquire($key)) {
                $this->queue->dispatch($message, self::REQUEUE_DELAY_SECONDS);

                return;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Saturation of anime #{animeId} could not start.', ['animeId' => $message->animeId, 'exception' => $e]);

            return;
        }

        try {
            $anime = $this->entityManager->find(Anime::class, $message->animeId);
            if (!$anime instanceof Anime) {
                $this->logger->debug('Anime #{animeId} no longer exists, dropping its saturation message.', ['animeId' => $message->animeId]);

                return;
            }

            $this->saturation->saturate($anime, $message->force, fn () => $this->jobLockService->heartbeat($key));
        } catch (\Throwable $e) {
            $this->logger->error('Saturation of anime #{animeId} failed.', ['animeId' => $message->animeId, 'exception' => $e]);
        } finally {
            try {
                $this->jobLockService->release($key);
            } catch (\Throwable $e) {
                $this->logger->error('Could not release the saturation lock of anime #{animeId}.', ['animeId' => $message->animeId, 'exception' => $e]);
            }
        }
    }
}
