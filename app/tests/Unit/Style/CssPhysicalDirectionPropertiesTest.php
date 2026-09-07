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
 * hand-written stylesheet so a physical property re-introduced later fails CI instead of
 * surfacing only when someone looks at an RTL locale with real eyes.
 *
 * Build output is out of scope (issue #616): app/public/css/app.css is compiled from
 * app/assets/scss and contains Bootstrap, whose physical properties are neither ours to fix nor
 * a problem — the RTL bundle next to it is produced by mirroring that output through RTLCSS. The
 * sources it is compiled from are scanned instead, so the rule still covers everything we write.
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

    /**
     * Собранные шагом сборки файлы: лежат рядом с рукописными, но в git не хранятся.
     */
    private const GENERATED_STYLESHEETS = ['app.css', 'app.rtl.css'];

    public function testNoPhysicalDirectionPropertiesInCss(): void
    {
        $appDir = \dirname(__DIR__, 3);

        $sources = array_merge(
            array_filter(
                glob($appDir.'/public/css/*.css') ?: [],
                static fn (string $file): bool => !\in_array(basename($file), self::GENERATED_STYLESHEETS, true),
            ),
            glob($appDir.'/assets/scss/*.scss') ?: [],
        );

        $failures = [];
        foreach ($sources as $file) {
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
            "Physical CSS direction properties found in hand-written stylesheets:\n".implode("\n", $failures),
        );
    }
}
