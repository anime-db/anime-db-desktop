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

namespace App\Service\I18nCoverage\Github;

use App\Service\I18nCoverage\CodeownersSource;

/**
 * Real {@see CodeownersSource}: reads `.github/CODEOWNERS` from the plugins repository via the
 * GitHub contents API rather than a `git` checkout — this script has no repository of its own to
 * check the plugins monorepo out into.
 */
final class GhCodeownersSource implements CodeownersSource
{
    private const string CODEOWNERS_PATH = '.github/CODEOWNERS';

    public function __construct(
        private readonly string $repo,
        private readonly GhCommand $gh = new GhCommand(),
    ) {
    }

    public function content(): string
    {
        $base64 = $this->gh->run([
            'api',
            \sprintf('repos/%s/contents/%s', $this->repo, self::CODEOWNERS_PATH),
            '--jq', '.content',
        ]);

        $decoded = base64_decode(str_replace(["\n", "\r"], '', $base64), true);
        if ($decoded === false) {
            throw new \RuntimeException(\sprintf('Unable to decode CODEOWNERS content from "%s".', $this->repo));
        }

        return $decoded;
    }
}
