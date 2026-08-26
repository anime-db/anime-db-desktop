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

namespace App\Tests\Unit\Service\I18nCoverage;

use App\Service\I18nCoverage\CodeownersOwnerResolver;
use PHPUnit\Framework\TestCase;

/**
 * Fixture shape matches the real `.github/CODEOWNERS` in `anime-db/anime-db-plugins` at the time
 * of writing: `/plugins/<id>/ @<handle>`, one line per plugin.
 */
final class CodeownersOwnerResolverTest extends TestCase
{
    private const string CODEOWNERS = <<<'CODEOWNERS'
        /plugins/animedb-language-pack/ @peter-gribanov
        /plugins/animedb-shikimori/     @peter-gribanov
        CODEOWNERS;

    public function testResolvesTheOwnerOfAListedPlugin(): void
    {
        self::assertSame(['@peter-gribanov'], CodeownersOwnerResolver::resolve(self::CODEOWNERS, 'animedb-language-pack'));
    }

    public function testReturnsNoOwnersForAPluginWithNoCodeownersEntry(): void
    {
        self::assertSame([], CodeownersOwnerResolver::resolve(self::CODEOWNERS, 'brand-new-plugin'));
    }

    public function testIgnoresCommentsAndBlankLines(): void
    {
        $content = "# default owners\n\n/plugins/animedb-language-pack/ @peter-gribanov\n";

        self::assertSame(['@peter-gribanov'], CodeownersOwnerResolver::resolve($content, 'animedb-language-pack'));
    }

    public function testSupportsMultipleOwnersOnOneLine(): void
    {
        $content = '/plugins/animedb-language-pack/ @peter-gribanov @second-owner';

        self::assertSame(['@peter-gribanov', '@second-owner'], CodeownersOwnerResolver::resolve($content, 'animedb-language-pack'));
    }

    public function testALaterMatchingLineOverridesAnEarlierOneForTheSamePlugin(): void
    {
        $content = "/plugins/animedb-language-pack/ @old-owner\n/plugins/animedb-language-pack/ @peter-gribanov\n";

        self::assertSame(['@peter-gribanov'], CodeownersOwnerResolver::resolve($content, 'animedb-language-pack'));
    }
}
