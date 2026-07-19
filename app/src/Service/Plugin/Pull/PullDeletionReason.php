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

namespace App\Service\Plugin\Pull;

/** Why a {@see PullDeletionNotice} was raised for an Anime missing from a pull() list. */
enum PullDeletionReason: string
{
    /** Not linked to any other currently active sync plugin — a single-source removal. */
    case DeletedFromSource = 'deleted_from_source';

    /** Still linked to at least one other currently active sync plugin — see PullDeletionNotice::$stillPresentOn. */
    case ConflictDeletedSourceButPresentOther = 'conflict_deleted_source_but_present_other';
}
