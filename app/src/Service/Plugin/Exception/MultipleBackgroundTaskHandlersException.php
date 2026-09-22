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

/**
 * Thrown by {@see \App\Service\Plugin\DependencyInjection\Compiler\TagPluginServicesPass}
 * (issue #702) when a plugin registers more than one service implementing
 * {@see \AnimeDb\PluginContracts\Background\BackgroundTaskHandlerInterface}: the contract promises
 * exactly one handler per plugin, covering all of that plugin's task kinds via
 * {@see \AnimeDb\PluginContracts\Background\BackgroundTask::$name}. This is a
 * container-compile-time failure, not a runtime one, same stance as
 * {@see MultipleSettingsPagesException} — a plugin author needs to see it immediately, not guess
 * later why half of its queued tasks silently stopped running.
 */
final class MultipleBackgroundTaskHandlersException extends \LogicException
{
    public function __construct(
        public readonly string $pluginId,
        public readonly string $firstServiceId,
        public readonly string $secondServiceId,
    ) {
        parent::__construct(\sprintf(
            'Plugin "%s" registers more than one background task handler service ("%s" and "%s"): exactly one is allowed.',
            $pluginId,
            $firstServiceId,
            $secondServiceId,
        ));
    }
}
