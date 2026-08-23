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

namespace App\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;

/**
 * `PhpCliCommand` exists because a PHP interpreter path cannot be invoked directly as `new
 * Process([$phpBinary, ...])`: under FrankenPHP that path resolves to `frankenphp.exe`, which
 * needs its `php-cli` subcommand prefixed first (see PhpCliCommand's own docblock). Bypassing it
 * has happened three times already (issues #410, #478, #492) — twice as a literal `\PHP_BINARY`,
 * once as the equivalent `(new PhpExecutableFinder())->find()` — and each time it went unnoticed
 * until CI or review, because both forms look correct under a plain system PHP.
 *
 * This scans app/src and app/tests for `new Process([...])` calls whose array literal starts
 * with a PHP interpreter path obtained either way, bypassing PhpCliCommand. It matches by intent
 * (any path sourced from `\PHP_BINARY` or `PhpExecutableFinder`), not by spelling, so a future
 * third way of naming "the current PHP binary" is still caught. It does not flag `new Process`
 * calls for other binaries (meilisearch, qbittorrent-nox, ...) — only a PHP interpreter path in
 * the first array slot triggers it. PhpCliCommand itself (which legitimately builds such an
 * array) and its test are the only allowed exception.
 */
final class NoDirectPhpInterpreterProcessTest extends TestCase
{
    /**
     * The only place allowed to put a PHP interpreter path into a Process argument array —
     * PhpCliCommand *is* the sanctioned way to do it, and its test exercises that directly.
     */
    private const array EXCLUDED_FILES = [
        'src/Service/Plugin/PhpCliCommand.php',
        'tests/Unit/Service/Plugin/PhpCliCommandTest.php',
    ];

    private const array PHP_INTERPRETER_MARKERS = ['PHP_BINARY', 'PhpExecutableFinder'];

    public function testProcessNeverInvokesPhpInterpreterDirectly(): void
    {
        $appDir = \dirname(__DIR__, 3);

        $violations = [];
        foreach (self::phpFiles($appDir.'/src') as $file) {
            $violations = [...$violations, ...self::findViolations($file, $appDir)];
        }
        foreach (self::phpFiles($appDir.'/tests') as $file) {
            $violations = [...$violations, ...self::findViolations($file, $appDir)];
        }

        $this->assertSame(
            [],
            $violations,
            "A PHP interpreter path reaches new Process([...]) directly, bypassing PhpCliCommand:\n"
            .implode("\n", $violations)
            .\PHP_EOL
            .'Wrap the interpreter path with PhpCliCommand::forScript()/forEval() instead of building the Process array by hand.',
        );
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private static function findViolations(string $file, string $appDir): array
    {
        $relativePath = ltrim(str_replace($appDir, '', $file), '/');
        if (\in_array($relativePath, self::EXCLUDED_FILES, true)) {
            return [];
        }

        $tokens = self::tokenize((string) file_get_contents($file));
        $dangerousVariables = self::dangerousVariables($tokens);

        $violations = [];
        $count = \count($tokens);
        for ($i = 0; $i < $count - 2; ++$i) {
            if (!self::isNewProcessCall($tokens, $i)) {
                continue;
            }

            $arguments = self::splitTopLevel($tokens, $i + 3);
            $firstArgument = $arguments[0] ?? [];
            $reason = self::phpInterpreterReason($firstArgument, $dangerousVariables);

            if ($reason !== null) {
                $violations[] = \sprintf(
                    '%s:%d — %s: %s',
                    $relativePath,
                    $firstArgument[0]['line'] ?? $tokens[$i]['line'],
                    $reason,
                    self::renderTokens($firstArgument),
                );
            }
        }

        return $violations;
    }

    /**
     * @param list<array{id: int|null, text: string, line: int}> $tokens
     */
    private static function isNewProcessCall(array $tokens, int $i): bool
    {
        if ($tokens[$i]['id'] !== T_NEW) {
            return false;
        }

        $className = $tokens[$i + 1];
        if (!\in_array($className['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }
        if ($className['text'] !== 'Process' && !str_ends_with($className['text'], '\\Process')) {
            return false;
        }

        return ($tokens[$i + 2]['text'] ?? null) === '(';
    }

    /**
     * Determines whether the first element of a `new Process([...])` array literal is a PHP
     * interpreter path reached without going through PhpCliCommand. Returns null when the
     * argument is not an array literal at all (e.g. `new Process(PhpCliCommand::forScript(...))`,
     * which is the sanctioned form and never reaches this check as a bare array).
     *
     * @param list<array{id: int|null, text: string, line: int}> $firstArgument
     * @param array<string, bool>                                $dangerousVariables
     */
    private static function phpInterpreterReason(array $firstArgument, array $dangerousVariables): ?string
    {
        if (($firstArgument[0]['text'] ?? null) !== '[') {
            return null;
        }

        $elements = self::splitTopLevel($firstArgument, 1);
        $firstElement = $elements[0] ?? [];

        if (self::containsMarker($firstElement, 'PHP_BINARY')) {
            return 'literal \PHP_BINARY used as the process command';
        }
        if (self::containsMarker($firstElement, 'PhpExecutableFinder')) {
            return 'PhpExecutableFinder result used as the process command';
        }
        if (\count($firstElement) === 1 && $firstElement[0]['id'] === T_VARIABLE) {
            $variableName = $firstElement[0]['text'];
            if ($dangerousVariables[$variableName] ?? false) {
                return \sprintf('%s holds a PHP interpreter path and is used as the process command', $variableName);
            }
        }

        return null;
    }

    /**
     * Variables assigned directly from `\PHP_BINARY` or `PhpExecutableFinder` somewhere in the
     * file — a coarse, file-wide (not scope-aware) heuristic that is precise enough for this
     * guard: this codebase never reuses a variable name for both a raw interpreter path and
     * something unrelated within the same file.
     *
     * @param list<array{id: int|null, text: string, line: int}> $tokens
     *
     * @return array<string, bool>
     */
    private static function dangerousVariables(array $tokens): array
    {
        $dangerous = [];
        $count = \count($tokens);
        for ($i = 0; $i < $count - 1; ++$i) {
            if ($tokens[$i]['id'] !== T_VARIABLE || ($tokens[$i + 1]['text'] ?? null) !== '=') {
                continue;
            }

            $variableName = $tokens[$i]['text'];
            $statement = self::statementTokensAfter($tokens, $i + 2);
            $dangerous[$variableName] = self::containsMarker($statement, 'PHP_BINARY')
                || self::containsMarker($statement, 'PhpExecutableFinder');
        }

        return $dangerous;
    }

    /**
     * @param list<array{id: int|null, text: string, line: int}> $tokens
     *
     * @return list<array{id: int|null, text: string, line: int}>
     */
    private static function statementTokensAfter(array $tokens, int $start): array
    {
        $depth = 0;
        $statement = [];
        for ($i = $start; $i < \count($tokens); ++$i) {
            $text = $tokens[$i]['text'];
            if ($text === ';' && $depth === 0) {
                break;
            }
            if (\in_array($text, ['(', '[', '{'], true)) {
                ++$depth;
            } elseif (\in_array($text, [')', ']', '}'], true)) {
                --$depth;
            }
            $statement[] = $tokens[$i];
        }

        return $statement;
    }

    /**
     * @param list<array{id: int|null, text: string, line: int}> $tokens
     */
    private static function containsMarker(array $tokens, string $marker): bool
    {
        if (!\in_array($marker, self::PHP_INTERPRETER_MARKERS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown marker "%s".', $marker));
        }

        foreach ($tokens as $token) {
            if (!\in_array($token['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            // A leading "\" before an unqualified name (\PHP_BINARY) tokenizes as a single
            // T_NAME_FULLY_QUALIFIED token rather than T_STRING, so match on suffix too.
            if ($token['text'] === $marker || str_ends_with($token['text'], '\\'.$marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Splits a bracketed token region into its top-level comma-separated segments. `$start` must
     * point at the token right after the opening bracket (`(`, `[` or `array(`'s `(`); the
     * matching closing bracket is located by depth-tracking rather than assumed.
     *
     * @param list<array{id: int|null, text: string, line: int}> $tokens
     *
     * @return list<list<array{id: int|null, text: string, line: int}>>
     */
    private static function splitTopLevel(array $tokens, int $start): array
    {
        $depth = 1;
        $segments = [];
        $current = [];
        for ($i = $start; $i < \count($tokens); ++$i) {
            $text = $tokens[$i]['text'];

            if (\in_array($text, ['(', '[', '{'], true)) {
                ++$depth;
            } elseif (\in_array($text, [')', ']', '}'], true)) {
                --$depth;
                if ($depth === 0) {
                    break;
                }
            } elseif ($text === ',' && $depth === 1) {
                $segments[] = $current;
                $current = [];
                continue;
            }

            $current[] = $tokens[$i];
        }
        if ($current !== []) {
            $segments[] = $current;
        }

        return $segments;
    }

    /**
     * @param list<array{id: int|null, text: string, line: int}> $tokens
     */
    private static function renderTokens(array $tokens): string
    {
        return trim(implode(' ', array_map(static fn (array $token): string => $token['text'], $tokens)));
    }

    /**
     * Tokenizes source code, dropping whitespace/comments and flattening PHP's mixed
     * array-or-string token shape into a uniform list.
     *
     * @return list<array{id: int|null, text: string, line: int}>
     */
    private static function tokenize(string $code): array
    {
        $tokens = [];
        $line = 1;
        foreach (token_get_all($code) as $token) {
            if (\is_array($token)) {
                $line = $token[2];
                if (\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $tokens[] = ['id' => $token[0], 'text' => $token[1], 'line' => $line];
            } else {
                $tokens[] = ['id' => null, 'text' => $token, 'line' => $line];
            }
        }

        return $tokens;
    }
}
