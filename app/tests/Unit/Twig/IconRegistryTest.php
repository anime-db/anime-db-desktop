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

use App\Twig\Exception\UnknownIconException;
use App\Twig\IconExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Loader\ArrayLoader;

/**
 * Issue #828/#829: the icon name passed to `icon()` must resolve to an actual file in
 * templates/icons/, and that must hold regardless of how the call is written in a template —
 * previously this was only checked by a regex scanning the .twig sources for one specific
 * `include('_icon.html.twig', {name: 'x'})` spelling, so a typo written any other way (different
 * quoting, whitespace, `with`, a variable from outside a `*Icons*` map, ...) slipped through with
 * the test still green, and `source()` on the missing file would only blow up at render time in
 * production (500). IconExtension::render() now validates the name against the real files on
 * disk on every call, so this is tested directly against the extension instead of the templates'
 * source text — fail closed, not fail open.
 */
final class IconRegistryTest extends TestCase
{
    private function iconsDir(): string
    {
        return \dirname(__DIR__, 3).'/templates/icons';
    }

    public function testKnownIconNameRendersItsFileTaggedWithTheRequestedName(): void
    {
        $extension = new IconExtension($this->iconsDir());

        $html = $extension->render('trash');

        self::assertStringStartsWith('<svg data-icon="trash" ', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
        self::assertSame(
            file_get_contents($this->iconsDir().'/trash.svg'),
            str_replace('data-icon="trash" ', '', $html),
            'Expected render() to return the file content unchanged apart from the added data-icon attribute.',
        );
    }

    public function testUnknownIconNameThrows(): void
    {
        $extension = new IconExtension($this->iconsDir());

        $this->expectException(UnknownIconException::class);
        $extension->render('not-a-real-icon');
    }

    #[DataProvider('namesThatMustNeverReachTheFilesystem')]
    public function testNameWithPathTraversalOrUnexpectedCharactersThrows(string $name): void
    {
        $extension = new IconExtension($this->iconsDir());

        $this->expectException(UnknownIconException::class);
        $extension->render($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesThatMustNeverReachTheFilesystem(): iterable
    {
        yield 'parent directory traversal' => ['../_icon.html.twig'];
        yield 'absolute path' => ['/etc/passwd'];
        yield 'uppercase' => ['Trash'];
        yield 'empty string' => [''];
    }

    /**
     * Issue #844: PCRE's `$` anchor (without the `D` modifier) matches just before a final `\n`,
     * so `/^[a-z0-9-]+$/` alone would accept "trash\n". Against the real templates/icons directory
     * that alone would not be observable — "trash\n.svg" is simply not a file that exists, so
     * is_file() rejects it regardless of the regex. This test instead points at a fixture
     * directory that *does* contain a file literally named with a trailing newline, so only the
     * regex anchor stands between a crafted icon name and that file being served.
     */
    public function testNameWithTrailingNewlineThrowsEvenWhenAMatchingFileExistsOnDisk(): void
    {
        $iconsDir = sys_get_temp_dir().'/anime-icon-registry-test-'.uniqid();
        mkdir($iconsDir, recursive: true);

        try {
            file_put_contents($iconsDir."/trash\n.svg", '<svg></svg>');

            $extension = new IconExtension($iconsDir);

            $this->expectException(UnknownIconException::class);
            $extension->render("trash\n");
        } finally {
            unlink($iconsDir."/trash\n.svg");
            rmdir($iconsDir);
        }
    }

    /**
     * Issue #829 review comment: a fixture template calling `icon()` with a bad name must fail
     * this test, exercised through the real Twig rendering pipeline — not just the PHP class
     * directly above — so a regression in how the function is wired into Twig (not just in
     * IconExtension itself) is caught too.
     */
    public function testRenderingATemplateThatReferencesAnUnknownIconThrows(): void
    {
        $twig = new Environment(new ArrayLoader([
            'broken.html.twig' => "{{ icon('not-a-real-icon') }}",
        ]));
        $twig->addExtension(new IconExtension($this->iconsDir()));

        $this->expectException(RuntimeError::class);
        try {
            $twig->render('broken.html.twig');
        } catch (RuntimeError $error) {
            self::assertInstanceOf(UnknownIconException::class, $error->getPrevious());

            throw $error;
        }
    }

    /**
     * Hygiene, not a safety net: an SVG nobody references is dead weight, but leaving one behind
     * cannot cause the 500 the `icon()` validation above guards against. Best-effort literal-name
     * scan is good enough here.
     *
     * @return list<string>
     */
    private function referencedIconNames(): array
    {
        $names = [];

        $finder = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(\dirname(__DIR__, 3).'/templates', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($finder as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());

            // Literal calls: {{ icon('trash') }}
            if (preg_match_all("/icon\\(\\s*'([a-z0-9-]+)'\\s*\\)/", $content, $matches)) {
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

    public function testNoUnusedIconFilesInTheDirectory(): void
    {
        $referenced = $this->referencedIconNames();
        self::assertNotEmpty($referenced, 'Expected to find at least one icon reference in the templates.');

        $files = glob($this->iconsDir().'/*.svg');
        self::assertNotFalse($files);
        $available = array_map(static fn (string $path): string => basename($path, '.svg'), $files);

        foreach ($available as $name) {
            self::assertContains(
                $name,
                $referenced,
                \sprintf('templates/icons/%s.svg is not referenced by any template.', $name),
            );
        }
    }
}
