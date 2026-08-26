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

namespace App\Service\I18nCoverage;

/**
 * Result of {@see I18nCoverageIssueDecider::decide()} — one of the five {@see I18nCoverageAction}
 * outcomes, plus whatever rendered text that outcome needs. `body`/`comment` are computed here
 * (inside the pure core) rather than by whoever applies the decision, so a `--dry-run` CLI can
 * print the exact text a real run would have written without touching an executor at all.
 */
final class I18nCoverageDecision
{
    /**
     * @param list<string> $missingKeys
     */
    private function __construct(
        public readonly I18nCoverageAction $action,
        public readonly array $missingKeys,
        public readonly ?string $body,
        public readonly ?string $comment,
    ) {
    }

    public static function none(): self
    {
        return new self(I18nCoverageAction::NONE, [], null, null);
    }

    public static function close(): self
    {
        return new self(I18nCoverageAction::CLOSE, [], null, null);
    }

    /**
     * @param list<string> $missingKeys
     */
    public static function create(array $missingKeys, string $body): self
    {
        return new self(I18nCoverageAction::CREATE, $missingKeys, $body, null);
    }

    /**
     * @param list<string> $missingKeys
     */
    public static function rewriteBody(array $missingKeys, string $body): self
    {
        return new self(I18nCoverageAction::REWRITE_BODY, $missingKeys, $body, null);
    }

    /**
     * @param list<string> $missingKeys
     */
    public static function rewriteBodyAndComment(array $missingKeys, string $body, string $comment): self
    {
        return new self(I18nCoverageAction::REWRITE_BODY_AND_COMMENT, $missingKeys, $body, $comment);
    }
}
