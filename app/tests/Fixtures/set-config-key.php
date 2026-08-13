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
 * Standalone worker for AppConfigStoreConcurrencyTest: sets a single top-level key of
 * config.json under an exclusive flock, sleeping for $sleepMicroseconds *while holding the
 * lock* to widen the race window a second, concurrently started instance of this script would
 * otherwise have to slip through. Run two of these against the same config.json at once, each
 * writing a different key — without AppConfigStore's flock() covering the whole read-modify-write
 * cycle, one process's read (taken before the other's write lands) overwrites the other's key
 * when it writes the full array back (issue #342).
 *
 * The lock acquire itself is non-blocking with only a short bounded retry, so a writer that loses
 * the race outright gets AppConfigStoreLockedException rather than queueing behind the holder.
 * This script retries at its own, more patient pace on that exception, the same pattern a real
 * caller is expected to follow, so both keys still end up persisted regardless of how the two
 * processes happen to interleave.
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Service\AppConfigStore;
use App\Service\Exception\AppConfigStoreLockedException;

[, $path, $key, $value, $sleepMicroseconds] = $argv;

$store = new AppConfigStore($path);
$maxAttempts = 50;

for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
    try {
        $store->update(static function (array $config) use ($key, $value, $sleepMicroseconds): array {
            usleep((int) $sleepMicroseconds);
            $config[$key] = $value;

            return $config;
        });

        break;
    } catch (AppConfigStoreLockedException $e) {
        if ($attempt === $maxAttempts) {
            throw $e;
        }

        usleep(10_000);
    }
}
