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

namespace App\Entity;

use App\Entity\ValueObject\PluginId;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ledger of the files a plugin wrote into a record folder (issue #1065): the sha256 of what was
 * last written under `file_name`, so the writer can tell its own file from one the user changed or
 * put there. Size and mtime are deliberately not stored — they do not survive a restored backup or
 * a copy. See {@see \App\Service\Folder\RecordFolderWriter}, the only reader/writer of this table.
 *
 * `anime_id` cascades on delete (the rows go with the record); there is no FK on `plugin_id`, the
 * rows of a removed plugin are deleted by {@see \App\Service\Plugin\PluginRemover}. Files on disk
 * are never touched by either.
 */
#[ORM\Entity]
#[ORM\Table(name: 'record_folder_file')]
#[ORM\Index(name: 'IDX_RECORD_FOLDER_FILE_ANIME', columns: ['anime_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_RECORD_FOLDER_FILE_PLUGIN_ANIME_NAME', columns: ['plugin_id', 'anime_id', 'file_name'])]
class RecordFolderFile
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Anime::class)]
    #[ORM\JoinColumn(name: 'anime_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public readonly Anime $anime;

    #[ORM\Column(name: 'plugin_id', length: 128)]
    private readonly string $pluginId;

    #[ORM\Column(name: 'file_name', length: 255)]
    public readonly string $fileName;

    #[ORM\Column(length: 64)]
    public private(set) string $sha256;

    #[ORM\Column(name: 'written_at', type: 'unix_timestamp')]
    public private(set) \DateTimeImmutable $writtenAt;

    public function __construct(Anime $anime, PluginId $pluginId, string $fileName, string $sha256, \DateTimeImmutable $writtenAt)
    {
        $this->anime = $anime;
        $this->pluginId = (string) $pluginId;
        $this->fileName = $fileName;
        $this->sha256 = $sha256;
        $this->writtenAt = $writtenAt;
    }

    public function getPluginId(): string
    {
        return $this->pluginId;
    }

    public function record(string $sha256, \DateTimeImmutable $writtenAt): void
    {
        $this->sha256 = $sha256;
        $this->writtenAt = $writtenAt;
    }
}
