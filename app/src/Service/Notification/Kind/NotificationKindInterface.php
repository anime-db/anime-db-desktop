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

namespace App\Service\Notification\Kind;

use App\Service\Notification\NotificationKindDefinition;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One notification kind: one class per kind in this namespace. The class holds the definition and
 * the kind's typed static factory method returning a {@see \App\Service\Notification\NotificationDraft};
 * sources build drafts only through that method. Adding a kind is a new class here, its ru/en texts
 * and a fixture — the registry and `NotificationDraft` are not touched.
 */
#[AutoconfigureTag('app.notification_kind')]
interface NotificationKindInterface
{
    public function definition(): NotificationKindDefinition;
}
