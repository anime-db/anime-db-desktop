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
 * Issue #834 review: on the catalog page, the top-nav "Add" menu's open `.dropdown-menu` used to
 * render underneath `.anime-list__chips`'s own `position: sticky` row (z-index 1020, see
 * _anime-list.scss) — Bootstrap's dropdown is only z-index 1000 and `.app-nav` created no
 * stacking context of its own, so the sticky chips row from the page body won, swallowing clicks
 * on the menu's lower items. Pinned as a source-text assertion (same approach as
 * {@see PluginWidgetCarouselStyleTest}), since "renders above sticky page content" is not
 * otherwise observable from rendered HTML alone.
 */
final class NavStackingContextTest extends TestCase
{
    public function testAppNavEstablishesAStackingContextAboveStickyPageContent(): void
    {
        $path = \dirname(__DIR__, 3).'/assets/scss/_nav.scss';
        $scss = file_get_contents($path);
        self::assertNotFalse($scss, \sprintf('Could not read "%s".', $path));

        $ruleStart = strpos($scss, '.app-nav {');
        $this->assertNotFalse($ruleStart, '.app-nav rule not found.');
        $ruleEnd = strpos($scss, '}', $ruleStart);
        $this->assertNotFalse($ruleEnd);
        $rule = substr($scss, $ruleStart, $ruleEnd - $ruleStart);

        $this->assertStringContainsString('position: relative', $rule);
        // $zindex-fixed (1030): one tier above the page body's own sticky content (1020).
        $this->assertStringContainsString('z-index: 1030', $rule);
    }
}
