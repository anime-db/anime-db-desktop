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
 * A physical CSS property (margin-left, text-align: left, ...) hardcodes the LTR reading
 * direction and does not flip for an RTL locale (issue #450). The logical equivalent
 * (margin-inline-start, text-align: start, inset-inline-*, ...) follows the "dir" attribute the
 * core sets from the locale, with no per-locale CSS override needed. This test scans every
 * stylesheet under app/public/css/ so a physical property re-introduced later fails CI instead of
 * surfacing only when someone looks at an RTL locale with real eyes.
 */
final class CssPhysicalDirectionPropertiesTest extends TestCase
{
    /**
     * Property name (without leading "border-"/"margin-"/"padding-") mapped to its logical
     * replacement, used only to build a helpful failure message.
     */
    private const FORBIDDEN_PATTERNS = [
        '/\bmargin-left\s*:/i' => 'margin-inline-start',
        '/\bmargin-right\s*:/i' => 'margin-inline-end',
        '/\bpadding-left\s*:/i' => 'padding-inline-start',
        '/\bpadding-right\s*:/i' => 'padding-inline-end',
        '/\bborder-left\b[a-z-]*\s*:/i' => 'border-inline-start*',
        '/\bborder-right\b[a-z-]*\s*:/i' => 'border-inline-end*',
        '/\btext-align\s*:\s*left\b/i' => 'text-align: start',
        '/\btext-align\s*:\s*right\b/i' => 'text-align: end',
        '/\bfloat\s*:\s*left\b/i' => 'inline-start-aware layout (e.g. flex/grid)',
        '/\bfloat\s*:\s*right\b/i' => 'inline-start-aware layout (e.g. flex/grid)',
        '/(?<![a-z-])left\s*:/i' => 'inset-inline-start',
        '/(?<![a-z-])right\s*:/i' => 'inset-inline-end',
    ];

    public function testNoPhysicalDirectionPropertiesInCss(): void
    {
        $cssDir = \dirname(__DIR__, 3).'/public/css';

        $failures = [];
        foreach (glob($cssDir.'/*.css') ?: [] as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $lineNumber => $line) {
                foreach (self::FORBIDDEN_PATTERNS as $pattern => $logicalReplacement) {
                    if (preg_match($pattern, $line) === 1) {
                        $failures[] = sprintf(
                            '%s:%d uses a physical direction property (use "%s" instead): %s',
                            basename($file),
                            $lineNumber + 1,
                            $logicalReplacement,
                            trim($line),
                        );
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $failures,
            "Physical CSS direction properties found under app/public/css/:\n".implode("\n", $failures),
        );
    }
}
