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

namespace App\EventSubscriber;

use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginCacheDirectories;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Removes `plugin-cache/` directories whose plugin is no longer installed — what
 * {@see \App\Service\Plugin\PluginRemover} could not delete at removal time. Runs once per PHP
 * process (the first main request), under the registry lock so the installed list cannot change
 * mid-cleanup. Failures are only logged.
 */
final class PluginCacheCleanupSubscriber implements EventSubscriberInterface
{
    private bool $done = false;

    public function __construct(
        private readonly InstalledPluginsRegistry $registry,
        private readonly PluginCacheDirectories $cacheDirectories,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => 'onKernelRequest'];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($this->done || !$event->isMainRequest()) {
            return;
        }
        $this->done = true;

        $this->registry->synchronized(function (): void {
            $this->cacheDirectories->removeOrphans(
                array_map(static fn ($plugin) => $plugin->id, $this->registry->all()),
            );
        });
    }
}
