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
 * Issue #828: every icon name a template passes to `_icon.html.twig` must resolve to an actual
 * file in templates/icons/, and that directory must not accumulate SVGs nobody references.
 */
final class IconRegistryTest extends TestCase
{
    private function templatesDir(): string
    {
        return \dirname(__DIR__, 3).'/templates';
    }

    /**
     * @return list<string>
     */
    private function referencedIconNames(): array
    {
        $names = [];

        $finder = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->templatesDir(), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($finder as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());

            // Direct includes: {{ include('_icon.html.twig', {name: 'trash'}) }}
            if (preg_match_all("/_icon\\.html\\.twig',\\s*\\{name:\\s*'([a-z0-9-]+)'/", $content, $matches)) {
                array_push($names, ...$matches[1]);
            }

            // Name-to-icon lookup maps, e.g. {% set themeIcons = {system: 'display', ...} %}
            if (preg_match_all('/set\\s+\\w*[Ii]cons\\w*\\s*=\\s*\\{([^}]*)\\}/', $content, $mapMatches)) {
                foreach ($mapMatches[1] as $mapBody) {
                    if (preg_match_all("/'([a-z0-9-]+)'/", $mapBody, $valueMatches)) {
                        array_push($names, ...$valueMatches[1]);
                    }
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    private function availableIconFiles(): array
    {
        $files = glob($this->templatesDir().'/icons/*.svg');
        self::assertNotFalse($files);

        return array_map(static fn (string $path): string => basename($path, '.svg'), $files);
    }

    public function testEveryReferencedIconNameHasAFile(): void
    {
        $referenced = $this->referencedIconNames();
        self::assertNotEmpty($referenced, 'Expected to find at least one icon reference in the templates.');

        $available = $this->availableIconFiles();

        foreach ($referenced as $name) {
            self::assertContains(
                $name,
                $available,
                \sprintf('Template references icon "%s" but templates/icons/%s.svg does not exist.', $name, $name),
            );
        }
    }

    public function testNoUnusedIconFilesInTheDirectory(): void
    {
        $referenced = $this->referencedIconNames();
        $available = $this->availableIconFiles();

        foreach ($available as $name) {
            self::assertContains(
                $name,
                $referenced,
                \sprintf('templates/icons/%s.svg is not referenced by any template.', $name),
            );
        }
    }
}
