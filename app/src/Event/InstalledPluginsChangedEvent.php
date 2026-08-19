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

namespace App\Event;

/**
 * Dispatched whenever the set of installed or enabled plugins may have changed:
 * {@see \App\Service\Plugin\InstalledPluginsRegistry::reconcile()} (install/uninstall, after the
 * on-disk index is rewritten) and {@see \App\Service\Plugin\PluginsConfigStore::updatePluginSettings()}
 * (enable/disable and any other per-plugin setting, after the write is persisted).
 *
 * Lets in-process state derived from the installed-plugin set — e.g.
 * {@see \App\Service\Plugin\AvailableLocalesProvider} (issue #453) — invalidate its cache right
 * when the underlying data changes, instead of re-deriving it on every request (which would mean
 * request-path I/O, see issue #84) or waiting for the next FrankenPHP worker restart.
 */
final class InstalledPluginsChangedEvent
{
}
