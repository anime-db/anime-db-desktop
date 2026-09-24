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

namespace App\Tests\Unit\Style;

use PHPUnit\Framework\TestCase;

/**
 * Issue #728: plugin/_widget_list.html.twig's cards must scroll in a single horizontal row, not
 * wrap into a vertical grid inside a height-capped slot — that combination is what produced both
 * a vertical scrollbar inside the catalog page's own vertical scroll and a cut-off bottom row.
 * Pins both halves of the fix as plain source-text assertions (same approach as
 * {@see CssPhysicalDirectionPropertiesTest}, since layout behaviour like
 * "no vertical scroll" is not otherwise observable from rendered HTML alone): the carousel rule
 * itself, and the removal of .anime-list__widgets > section's height cap that used to force the
 * vertical scroll.
 */
final class PluginWidgetCarouselStyleTest extends TestCase
{
    private function readScss(string $filename): string
    {
        $path = \dirname(__DIR__, 3).'/assets/scss/'.$filename;
        $contents = file_get_contents($path);
        self::assertNotFalse($contents, \sprintf('Could not read "%s".', $path));

        return $contents;
    }

    public function testWidgetListIsALaidOutAsASingleNoWrapHorizontalRow(): void
    {
        $scss = $this->readScss('_plugin-widget.scss');

        $listRuleStart = strpos($scss, '.plugin-widget__list {');
        $this->assertNotFalse($listRuleStart, '.plugin-widget__list rule not found.');
        $listRuleEnd = strpos($scss, '}', $listRuleStart);
        $this->assertNotFalse($listRuleEnd);
        $listRule = substr($scss, $listRuleStart, $listRuleEnd - $listRuleStart);

        $this->assertStringContainsString('display: flex', $listRule);
        $this->assertStringContainsString('flex-wrap: nowrap', $listRule);
        $this->assertStringContainsString('overflow-x: auto', $listRule);
        $this->assertStringNotContainsString('grid', $listRule);
    }

    public function testAnimeListWidgetsSlotNoLongerCapsHeightOrScrolls(): void
    {
        $scss = $this->readScss('_anime-list.scss');

        $this->assertStringNotContainsString('.anime-list__widgets > section', $scss);
        $this->assertStringNotContainsString('max-height: 320px', $scss);
    }
}
