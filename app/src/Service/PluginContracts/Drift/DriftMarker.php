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

namespace App\Service\PluginContracts\Drift;

use App\Service\PluginContracts\LagReason;

/**
 * What the previous run recorded in the issue body: the contracts version the app carried and why
 * the plugin lagged. The command keeps no state of its own; this is it.
 */
final class DriftMarker
{
    private const string PREFIX = '<!-- plugin-contracts-drift:state:';
    private const string SUFFIX = ' -->';

    public function __construct(
        public readonly string $contractsVersion,
        public readonly LagReason $reason,
    ) {
    }

    public function render(): string
    {
        return self::PREFIX.json_encode(['contracts_version' => $this->contractsVersion, 'reason' => $this->reason->value], \JSON_THROW_ON_ERROR).self::SUFFIX;
    }

    public function equals(self $other): bool
    {
        return $this->contractsVersion === $other->contractsVersion && $this->reason === $other->reason;
    }

    /**
     * @return self|null null when $body carries no marker or it does not parse (e.g. edited by hand)
     */
    public static function parse(string $body): ?self
    {
        $start = strpos($body, self::PREFIX);
        if ($start === false) {
            return null;
        }

        $jsonStart = $start + \strlen(self::PREFIX);
        $end = strpos($body, self::SUFFIX, $jsonStart);
        if ($end === false) {
            return null;
        }

        try {
            $decoded = json_decode(substr($body, $jsonStart, $end - $jsonStart), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded) || !\is_string($decoded['contracts_version'] ?? null) || !\is_string($decoded['reason'] ?? null)) {
            return null;
        }

        $reason = LagReason::tryFrom($decoded['reason']);

        return $reason !== null ? new self($decoded['contracts_version'], $reason) : null;
    }
}
