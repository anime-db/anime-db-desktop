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

namespace App\Service\Catalog;

use AnimeDb\PluginContracts\LinkRules\LinkRulesApplier;
use App\Entity\SourceUrl;
use App\Service\Plugin\LinkRulesProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Comparison form of a source url, the value of `anime_source.normalized_url`. It is only for
 * comparing links: the displayed url (`AnimeSource::$url`) is never replaced by it.
 *
 * Site rules come from plugins ({@see LinkRulesProvider}) and are applied here, not in
 * {@see SourceUrl::common()}: what the catalog stores for de-duplication must not depend on the
 * set of installed plugins.
 */
final class SourceUrlNormalizer
{
    /**
     * Bumped whenever the common steps or the way rules are applied change; it is part of the
     * rule set fingerprint, so such a change is noticed like a change of the rules themselves.
     */
    public const VERSION = 1;

    public function __construct(
        private readonly ?LinkRulesProvider $rules = null,
        private readonly LinkRulesApplier $applier = new LinkRulesApplier(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Common steps, then the first matching site rule (one pass), then the common steps again
     * over its result. The result of the common steps is returned when no rule matches or the
     * outcome exceeds {@see SourceUrl::MAX_LENGTH}.
     */
    public function normalize(string $url): string
    {
        $common = SourceUrl::common($url);
        $rules = $this->rules?->rules() ?? [];
        if ($rules === []) {
            return $common;
        }

        $applied = $this->applier->apply($rules, $common, function (string $expression, string $error): void {
            $this->logger->warning('Link rule failed and was skipped: {error}', [
                'expression' => $expression,
                'error' => $error,
            ]);
        });
        if ($applied === null) {
            return $common;
        }

        $result = SourceUrl::common($applied);

        return \strlen($result) > SourceUrl::MAX_LENGTH ? $common : $result;
    }
}
