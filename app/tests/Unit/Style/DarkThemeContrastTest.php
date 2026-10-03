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
 * Issue #879: measured against #212529, .btn-outline-secondary's resting state and the
 * --bs-primary text actions ("Ещё (k)", the "Filters · N" count) fell below WCAG AA (4.5:1) in
 * the dark theme only — the light theme already passes. Pinned as plain source-text assertions
 * (same approach as {@see NavStackingContextTest}), since "resolves to a high-enough contrast
 * ratio" is not otherwise observable from rendered HTML/CSS alone without a real browser.
 */
final class DarkThemeContrastTest extends TestCase
{
    private function readScss(string $filename): string
    {
        $path = \dirname(__DIR__, 3).'/assets/scss/'.$filename;
        $contents = file_get_contents($path);
        self::assertNotFalse($contents, \sprintf('Could not read "%s".', $path));

        return $contents;
    }

    // Balances braces rather than stopping at the first "}" (unlike NavStackingContextTest's
    // simpler version) because the rules this test extracts contain Sass interpolations
    // (`#{$gray-500}`), each a nested, self-closing brace pair that would otherwise be mistaken
    // for the end of the rule.
    private static function extractRule(string $scss, string $selectorNeedle): string
    {
        $ruleStart = strpos($scss, $selectorNeedle);
        self::assertNotFalse($ruleStart, \sprintf('"%s" rule not found.', $selectorNeedle));

        $pos = strpos($scss, '{', $ruleStart);
        self::assertNotFalse($pos, \sprintf('No opening brace found for "%s".', $selectorNeedle));
        $length = strlen($scss);
        $depth = 0;
        do {
            if ($scss[$pos] === '{') {
                ++$depth;
            } elseif ($scss[$pos] === '}') {
                --$depth;
            }
            ++$pos;
        } while ($depth > 0 && $pos < $length);

        self::assertSame(0, $depth, 'Unbalanced braces while extracting rule.');

        return substr($scss, $ruleStart, $pos - $ruleStart);
    }

    public function testBtnOutlineSecondaryGetsALighterRestingColorInDarkTheme(): void
    {
        $scss = $this->readScss('_theme.scss');

        $darkThemeStart = strpos($scss, '[data-bs-theme="dark"]');
        $this->assertNotFalse($darkThemeStart, '[data-bs-theme="dark"] block not found.');

        $rule = self::extractRule($scss, '.btn-outline-secondary {');
        $this->assertGreaterThan($darkThemeStart, strpos($scss, '.btn-outline-secondary {'));

        // $gray-500 (#adb5bd) measures 7.43:1 against #212529 — well above the 4.5:1 floor.
        $this->assertStringContainsString('--bs-btn-color: #{$gray-500}', $rule);
        $this->assertStringContainsString('--bs-btn-border-color: #{$gray-500}', $rule);
    }

    public function testPrimaryTextActionsSwitchToPrimaryTextEmphasisInDarkTheme(): void
    {
        $scss = $this->readScss('_theme.scss');

        $darkThemeStart = strpos($scss, '[data-bs-theme="dark"]');
        $this->assertNotFalse($darkThemeStart, '[data-bs-theme="dark"] block not found.');

        $rule = self::extractRule($scss, '.anime-list__filter-section-more,');
        $this->assertGreaterThan($darkThemeStart, strpos($scss, '.anime-list__filter-section-more,'));

        $this->assertStringContainsString('.anime-list__filters-count', $rule);
        // --bs-primary-text-emphasis (Bootstrap's own lightened primary for dark-on-dark text)
        // measures 5.89:1 against #212529, versus 3.31:1 for the plain --bs-primary it replaces.
        $this->assertStringContainsString('color: var(--bs-primary-text-emphasis)', $rule);
    }

    public function testActiveNavLinkNoLongerUsesPlainPrimaryForTextColor(): void
    {
        $scss = $this->readScss('_nav.scss');
        $rule = self::extractRule($scss, '.app-nav__link--active {');

        // --bs-primary measured 2.47:1 (dark) / 3.93:1 (light), both below 4.5:1. The accent
        // underline (box-shadow) stays — it is now the only color-based indicator of "active".
        $this->assertStringContainsString('color: var(--bs-emphasis-color)', $rule);
        $this->assertStringNotContainsString('color: var(--bs-primary)', $rule);
        $this->assertStringContainsString('box-shadow: inset 0 -3px 0 var(--adb-accent)', $rule);
    }
}
