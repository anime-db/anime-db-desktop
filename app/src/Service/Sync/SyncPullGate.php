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

namespace App\Service\Sync;

use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\PluginsConfigStore;
use Psr\Clock\ClockInterface;

/**
 * Decides whether a periodic pull (issue #870) is due for a plugin and records successful pulls.
 *
 * A pull is due when the plugin was already seeded (`syncSeeded`: the first pull of a plugin is
 * the connect-seed with its own entry point) and `syncLastPullAt` — the last *successful* pull,
 * seed or periodic — is missing, unparseable, older than {@see self::MAX_AGE_SECONDS} or in the
 * future (clock turned back). Activity of the plugin (`features.sync`) is checked by the caller
 * through {@see \App\Service\Plugin\SyncRegistry}.
 */
final class SyncPullGate
{
    public const string SETTING_LAST_PULL_AT = 'syncLastPullAt';
    public const int MAX_AGE_SECONDS = 6 * 60 * 60;

    public function __construct(
        private readonly PluginsConfigStore $pluginsConfigStore,
        private readonly ClockInterface $clock,
    ) {
    }

    public function isDue(PluginId $pluginId): bool
    {
        $settings = $this->pluginsConfigStore->getPluginSettings($pluginId);
        if (($settings['syncSeeded'] ?? false) !== true) {
            return false;
        }

        $lastPullAt = $settings[self::SETTING_LAST_PULL_AT] ?? null;
        if (!\is_string($lastPullAt)) {
            return true;
        }

        $lastPullAtDate = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $lastPullAt);
        if ($lastPullAtDate === false) {
            return true;
        }

        $now = $this->clock->now();
        if ($lastPullAtDate > $now) {
            return true;
        }

        return ($now->getTimestamp() - $lastPullAtDate->getTimestamp()) > self::MAX_AGE_SECONDS;
    }

    public function markPulled(PluginId $pluginId): void
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);

        $this->pluginsConfigStore->updatePluginSettings($pluginId, static function (array $settings) use ($now): array {
            $settings[self::SETTING_LAST_PULL_AT] = $now;

            return $settings;
        });
    }
}
