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

/*
 * Standalone worker for PluginsConfigStoreConcurrencyTest: increments the "counter" key of a
 * single plugin's settings under an exclusive flock, sleeping for $sleepMicroseconds *while
 * holding the lock* to widen the race window a second, concurrently started instance of this
 * script would otherwise have to slip through. Run two of these against the same plugins.json
 * at once — without PluginsConfigStore's flock() the read-modify-write cycles interleave and
 * one increment is lost.
 */

require __DIR__.'/../../../vendor/autoload.php';

use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\PluginsConfigStore;

[, $path, $pluginIdValue, $sleepMicroseconds] = $argv;

$store = new PluginsConfigStore($path);
$store->updatePluginSettings(new PluginId($pluginIdValue), static function (array $settings) use ($sleepMicroseconds): array {
    $counter = \is_int($settings['counter'] ?? null) ? $settings['counter'] : 0;
    usleep((int) $sleepMicroseconds);
    $settings['counter'] = $counter + 1;

    return $settings;
});
