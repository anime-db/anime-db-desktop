<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Translation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * TODO test (per issue #48): keeps the ru/en catalog dictionaries (genres, types, statuses)
 * from drifting apart - every key added to one locale must be added to the other.
 */
final class CatalogTranslationsTest extends TestCase
{
    public function testRuAndEnDictionariesHaveTheSameKeys(): void
    {
        $translationsDir = \dirname(__DIR__, 3).'/translations';

        $ru = $this->flattenKeys(Yaml::parseFile($translationsDir.'/messages.ru.yaml'));
        $en = $this->flattenKeys(Yaml::parseFile($translationsDir.'/messages.en.yaml'));

        sort($ru);
        sort($en);

        $this->assertSame($ru, $en);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function flattenKeys(array $data, string $prefix = ''): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (\is_array($value)) {
                $keys = [...$keys, ...$this->flattenKeys($value, $fullKey)];
            } else {
                $keys[] = $fullKey;
            }
        }

        return $keys;
    }
}
