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

/*
 * Standalone worker for InstalledPluginsRegistryConcurrencyTest: repeatedly calls reconcile()
 * against the same $pluginsDir a second, concurrently started instance of this script is also
 * hammering. Without InstalledPluginsRegistry::synchronized() covering writeIndex()'s temp-file
 * write + rename(), two of these interleaving on the fixed "installed-plugins.php.tmp" name used
 * to be able to publish a syntactically broken index — readIndex()'s require() on it is an
 * uncaught ParseError, which is exactly what this script would crash with (issue #420).
 */

require __DIR__.'/../../../vendor/autoload.php';

use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use Psr\Log\NullLogger;

[, $pluginsDir, $iterationsValue] = $argv;
$iterations = (int) $iterationsValue;

$registry = new InstalledPluginsRegistry(
    $pluginsDir,
    new PluginsConfigStore($pluginsDir.'/plugins.json'),
    new NullLogger(),
);

for ($i = 0; $i < $iterations; ++$i) {
    $registry->reconcile();
}
