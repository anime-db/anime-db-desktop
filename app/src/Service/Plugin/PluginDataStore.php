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

namespace App\Service\Plugin;

use AnimeDb\PluginContracts\Model\AnimeId;
use App\Entity\Anime;
use App\Entity\AnimePluginData;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\Exception\PluginDataWriteConflictException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Read/write access to one plugin's own row in `anime_plugin_data`, scoped to that plugin's own
 * {@see PluginId} — see {@see DependencyInjection\Compiler\PluginDataStoreScopePass}, which
 * constructs one instance per installed plugin and is the only place a plugin ever obtains one.
 *
 * write() retries on a lost race up to {@see self::MAX_WRITE_ATTEMPTS} times: happy-path is a
 * single flush(), but sync/scan/download all run in the background and may write the *same*
 * (anime, plugin) pair at the same time, which the row's `$version` column
 * ({@see AnimePluginData}) catches as {@see OptimisticLockException} — re-read to get a fresh
 * version, override with $data again, retry. write() is a full replace (contracts v0.8.0), so
 * the retry does not attempt to preserve whatever a concurrent writer left; it exists only to
 * get past the version conflict, not to merge onto it. A concurrent *first* write racing to
 * create the row (nothing to re-read yet) is caught the same way via the table's
 * UNIQUE(anime_id, plugin_id) constraint instead.
 *
 * Every attempt fetches a fresh {@see EntityManagerInterface} from {@see ManagerRegistry} rather
 * than reusing one held in a property: Doctrine closes the EntityManager after *any* failed
 * flush() (version conflict included — see UnitOfWork::commit()), so persist()/flush() on the
 * same instance would throw on every retry after the first conflict. `ManagerRegistry::
 * resetManager()` is the only way back to a usable one afterwards; `getManagerForClass()` alone
 * keeps handing back the same closed instance.
 */
class PluginDataStore implements PluginDataStoreInterface
{
    private const MAX_WRITE_ATTEMPTS = 3;

    public function __construct(
        private readonly PluginId $pluginId,
        private readonly ManagerRegistry $registry,
    ) {
    }

    public function read(AnimeId $anime): array
    {
        return $this->findRow($this->entityManager(), $anime->value)?->getPayload() ?? [];
    }

    public function write(AnimeId $anime, array $data): void
    {
        $lastConflict = null;

        for ($attempt = 1; $attempt <= self::MAX_WRITE_ATTEMPTS; ++$attempt) {
            $entityManager = $this->entityManager();

            try {
                $row = $this->findRow($entityManager, $anime->value);
                if ($row === null) {
                    $animeReference = $entityManager->getReference(Anime::class, $anime->value);
                    if ($animeReference === null) {
                        throw new \LogicException(\sprintf('No anime reference could be created for id #%d.', $anime->value));
                    }

                    $entityManager->persist(new AnimePluginData($animeReference, $this->pluginId, $data));
                } else {
                    $row->setPayload($data);
                }

                $entityManager->flush();

                return;
            } catch (OptimisticLockException|UniqueConstraintViolationException $exception) {
                $lastConflict = $exception;
            }
        }

        throw new PluginDataWriteConflictException($this->pluginId, $anime->value, self::MAX_WRITE_ATTEMPTS, $lastConflict);
    }

    private function findRow(EntityManagerInterface $entityManager, int $animeId): ?AnimePluginData
    {
        return $entityManager->createQueryBuilder()
            ->select('d')
            ->from(AnimePluginData::class, 'd')
            ->andWhere('d.anime = :animeId')
            ->andWhere('d.pluginId = :pluginId')
            ->setParameter('animeId', $animeId)
            ->setParameter('pluginId', (string) $this->pluginId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->registry->getManagerForClass(AnimePluginData::class);
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \LogicException('No EntityManager is registered for '.AnimePluginData::class.'.');
        }

        if (!$entityManager->isOpen()) {
            $this->registry->resetManager();
            $entityManager = $this->registry->getManagerForClass(AnimePluginData::class);
        }

        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
