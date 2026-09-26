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

final class DriftDecision
{
    private function __construct(
        public readonly DriftAction $action,
        public readonly ?string $body,
        public readonly ?string $comment,
        public readonly ?string $problem,
    ) {
    }

    public static function none(): self
    {
        return new self(DriftAction::NONE, null, null, null);
    }

    public static function close(): self
    {
        return new self(DriftAction::CLOSE, null, null, null);
    }

    public static function create(string $body): self
    {
        return new self(DriftAction::CREATE, $body, null, null);
    }

    public static function rewriteBody(string $body): self
    {
        return new self(DriftAction::REWRITE_BODY, $body, null, null);
    }

    public static function rewriteBodyAndComment(string $body, string $comment): self
    {
        return new self(DriftAction::REWRITE_BODY_AND_COMMENT, $body, $comment, null);
    }

    public static function cannotCheck(string $problem): self
    {
        return new self(DriftAction::CANNOT_CHECK, null, null, $problem);
    }
}
