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

namespace App\Entity;

use App\Entity\ValueObject\PluginId;
use Doctrine\ORM\Mapping as ORM;

/**
 * One plugin's own slice of data for one anime (issue #299), row-level rather than a shared
 * JSON column: Anime::metadata['plugins'][pluginId] used to hold every plugin's data in the
 * same column, and Doctrine always writes that column whole (no partial-JSON-update), so two
 * background flows (sync/scan/download) writing different plugins' data for the same anime at
 * the same time could lose one write. A row per (anime, plugin) means different plugins never
 * collide at all, and the same (anime, plugin) pair written twice concurrently is caught by the
 * `$version` optimistic lock below — see {@see \App\Service\Plugin\PluginDataStore}, which is
 * the only intended writer/reader of this table.
 *
 * `anime_id` cascades on delete (removing an anime removes its plugin data with it), but there
 * is deliberately no FK on `plugin_id` to anything — a plugin's data survives uninstall/reinstall
 * of that plugin so a reinstalled plugin re-links to what it already knew instead of refilling
 * from scratch.
 */
#[ORM\Entity]
#[ORM\Table(name: 'anime_plugin_data')]
#[ORM\UniqueConstraint(name: 'UNIQ_ANIME_PLUGIN_DATA_ANIME_PLUGIN', columns: ['anime_id', 'plugin_id'])]
class AnimePluginData
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Anime::class)]
    #[ORM\JoinColumn(name: 'anime_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public readonly Anime $anime;

    #[ORM\Column(name: 'plugin_id', length: 128)]
    private readonly string $pluginId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload;

    /**
     * Doctrine's optimistic lock: every UPDATE checks this column and bumps it, failing with
     * {@see \Doctrine\ORM\OptimisticLockException} if another process already changed the row
     * since this one read it. See {@see \App\Service\Plugin\PluginDataStore::write()} for the
     * re-read/merge/retry this drives.
     */
    #[ORM\Version, ORM\Column(type: 'integer')]
    private int $version = 1;

    /** @param array<string, mixed> $payload */
    public function __construct(Anime $anime, PluginId $pluginId, array $payload)
    {
        $this->anime = $anime;
        $this->pluginId = (string) $pluginId;
        $this->payload = $payload;
    }

    public function getPluginId(): PluginId
    {
        return new PluginId($this->pluginId);
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Merges $data onto the currently-stored payload, keeping keys already present that $data
     * does not mention — the same merge semantics {@see Anime}'s former
     * `putPluginData()` had before this table replaced `metadata['plugins']`.
     *
     * @param array<string, mixed> $data
     */
    public function mergePayload(array $data): void
    {
        $this->payload = [...$this->payload, ...$data];
    }
}
