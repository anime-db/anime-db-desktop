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

namespace App\Service\Plugin\Exception;

use App\Entity\ValueObject\PluginId;

/**
 * Thrown by {@see \App\Service\Plugin\WidgetActiveTrait::changeActive()} (issue #213) when
 * enabling a widget would push its placement (anime detail page or catalog — each has its own
 * count) past the hard limit of simultaneously active widgets. The settings UI is expected to
 * catch this and show it as a validation error, not a fatal one — a second browser tab racing
 * the same limit is the only realistic way to hit this once the UI itself disables the toggle.
 */
final class WidgetHardLimitExceededException extends \DomainException
{
    public function __construct(
        public readonly PluginId $pluginId,
        public readonly string $widgetName,
        public readonly int $limit,
    ) {
        parent::__construct(\sprintf(
            'Cannot enable widget "%s" of plugin "%s": hard limit of %d active widgets for this placement reached.',
            $widgetName,
            $pluginId,
            $limit,
        ));
    }
}
