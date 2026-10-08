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

namespace App\Tests\Unit\Distribution;

use PHPUnit\Framework\TestCase;

/**
 * Reconciles resources/third-party-licenses/COMPOSER-PACKAGES.md with the production dependencies
 * in composer.lock and with the license files actually present under vendor/. The inventory is
 * generated (scripts/generate-composer-licenses.js), so the realistic failure is a
 * `composer update` that nobody follows with a regeneration.
 */
final class ComposerPackagesInventoryTest extends TestCase
{
    private const REGENERATE = 'run: node scripts/generate-composer-licenses.js';

    public function testInventoryListsExactlyTheProductionPackages(): void
    {
        $locked = $this->lockedPackages();
        $listed = $this->inventory();

        self::assertSame(
            [],
            array_values(array_diff(array_keys($locked), array_keys($listed))),
            'Packages missing from COMPOSER-PACKAGES.md; '.self::REGENERATE,
        );
        self::assertSame(
            [],
            array_values(array_diff(array_keys($listed), array_keys($locked))),
            'COMPOSER-PACKAGES.md lists packages that are not production dependencies; '.self::REGENERATE,
        );

        $mismatched = [];
        foreach ($locked as $name => $package) {
            if ($listed[$name]['version'] !== $package['version'] || $listed[$name]['license'] !== $package['license']) {
                $mismatched[] = $name;
            }
        }
        self::assertSame([], $mismatched, 'Version or license differs from composer.lock; '.self::REGENERATE);
    }

    public function testEveryPackageShipsALicenseFileTheInventoryPointsAt(): void
    {
        $vendor = \dirname(__DIR__, 3).'/vendor';
        $missing = [];
        foreach ($this->inventory() as $name => $row) {
            $path = $row['text'];
            $prefix = 'resources/app/app/vendor/';
            if (!str_starts_with($path, $prefix) || !is_file($vendor.'/'.substr($path, \strlen($prefix)))) {
                $missing[] = $name;
            }
        }

        self::assertSame([], $missing, 'Packages without a license file in their directory (or a stale path); '.self::REGENERATE);
    }

    /**
     * @return array<string, array{version: string, license: string}>
     */
    private function lockedPackages(): array
    {
        $lock = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/composer.lock'), true, 512, \JSON_THROW_ON_ERROR);
        $packages = [];
        foreach ($lock['packages'] as $package) {
            $packages[$package['name']] = [
                'version' => $package['version'],
                'license' => implode(', ', $package['license'] ?? []) ?: 'none',
            ];
        }

        return $packages;
    }

    /**
     * @return array<string, array{version: string, license: string, text: string}>
     */
    private function inventory(): array
    {
        $file = \dirname(__DIR__, 4).'/resources/third-party-licenses/COMPOSER-PACKAGES.md';
        $rows = [];
        foreach (file($file, \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $cells = array_map('trim', explode('|', trim($line, '| ')));
            if (\count($cells) !== 4 || !preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $cells[0])) {
                continue;
            }
            $rows[$cells[0]] = ['version' => $cells[1], 'license' => $cells[2], 'text' => $cells[3]];
        }
        self::assertNotSame([], $rows, 'No package rows parsed from COMPOSER-PACKAGES.md');

        return $rows;
    }
}
