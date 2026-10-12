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

namespace App\Service\Notification;

use App\Service\Notification\Kind\NotificationKindInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The set of notification kind definitions, collected from the {@see NotificationKindInterface} implementations, tagged `app.notification_kind`. A kind stored in the
 * database but absent here is a normal case (see {@see NotificationRenderer}), hence tryFrom().
 * Public so the renderability gate test can read the real set from the container.
 */
#[Autoconfigure(public: true)]
class NotificationKindRegistry
{
    /** @var array<string, NotificationKindDefinition> */
    private array $definitions = [];

    /**
     * @param iterable<NotificationKindInterface> $kinds
     */
    public function __construct(
        #[AutowireIterator('app.notification_kind')]
        iterable $kinds = [],
    ) {
        foreach ($kinds as $kind) {
            $definition = $kind->definition();
            $this->definitions[$definition->kind] = $definition;
        }
    }

    public function tryFrom(string $kind): ?NotificationKindDefinition
    {
        return $this->definitions[$kind] ?? null;
    }

    /** @return list<NotificationKindDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }
}
