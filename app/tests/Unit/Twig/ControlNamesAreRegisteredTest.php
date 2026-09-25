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

namespace App\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;

/**
 * Every name used in a `data-control` attribute of the application templates must be registered
 * with `registerControl()` in app/assets/js. An unknown name is only reported at runtime by a
 * visible alert inside the element, so nothing else would catch a typo or a missing control.
 */
final class ControlNamesAreRegisteredTest extends TestCase
{
    private const ATTRIBUTE_PATTERN = '/\bdata-control\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/';

    public function testControlNamesInTemplatesAreLiterals(): void
    {
        $dynamic = [];
        foreach ($this->templateAttributeValues() as $file => $values) {
            foreach ($values as $value) {
                if (str_contains($value, '{{') || str_contains($value, '{%')) {
                    $dynamic[] = sprintf('%s: data-control="%s"', $file, $value);
                }
            }
        }

        self::assertSame(
            [],
            $dynamic,
            "The data-control value must be a literal: a name built in the template cannot be checked\n"
            .'against the registered controls, so the invariant would silently stop working. Found:'."\n"
            .implode("\n", $dynamic)
        );
    }

    public function testEveryControlNameInTemplatesIsRegistered(): void
    {
        $registered = $this->registeredControls();
        self::assertNotEmpty($registered, 'No registerControl() calls found in app/assets/js: the pattern or path is broken.');

        $used = [];
        foreach ($this->templateAttributeValues() as $file => $values) {
            foreach ($values as $value) {
                if (str_contains($value, '{{') || str_contains($value, '{%')) {
                    continue; // reported by testControlNamesInTemplatesAreLiterals()
                }
                // Same parsing as controller.js: value.split(/\s+/).filter(Boolean)
                foreach (preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $name) {
                    $used[$name][$file] = true;
                }
            }
        }
        self::assertNotEmpty($used, 'No data-control attributes found in app/templates: the pattern or path is broken.');

        $errors = [];
        foreach ($used as $name => $files) {
            if (!in_array($name, $registered, true)) {
                $errors[] = sprintf('"%s" (in %s)', $name, implode(', ', array_keys($files)));
            }
        }

        self::assertSame(
            [],
            $errors,
            "Unknown data-control names, not registered via registerControl() in app/assets/js:\n".implode("\n", $errors)
        );
    }

    /**
     * @return array<string, list<string>> attribute values by template path relative to app/templates
     */
    private function templateAttributeValues(): array
    {
        $root = dirname(__DIR__, 3).'/templates';
        $result = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) {
                continue;
            }
            $content = (string) file_get_contents($file->getPathname());
            if (preg_match_all(self::ATTRIBUTE_PATTERN, $content, $matches, PREG_SET_ORDER) === 0) {
                continue;
            }
            $path = substr($file->getPathname(), strlen($root) + 1);
            foreach ($matches as $match) {
                $result[$path][] = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
            }
        }
        ksort($result);

        return $result;
    }

    /**
     * @return list<string>
     */
    private function registeredControls(): array
    {
        $names = [];
        foreach (glob(dirname(__DIR__, 3).'/assets/js/*.js') ?: [] as $file) {
            $content = (string) file_get_contents($file);
            if (preg_match_all('/\bregisterControl\(\s*([\'"])([^\'"]+)\1\s*,/', $content, $matches) > 0) {
                array_push($names, ...$matches[2]);
            }
        }

        return array_values(array_unique($names));
    }
}
