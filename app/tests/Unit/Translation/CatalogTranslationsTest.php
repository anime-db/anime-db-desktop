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

namespace App\Tests\Unit\Translation;

use App\Service\Translation\TranslationCatalog;
use PHPUnit\Framework\TestCase;

/**
 * TODO test (per issue #48): keeps the ru/en catalog dictionaries (genres, types, statuses)
 * from drifting apart - every key added to one locale must be added to the other.
 */
final class CatalogTranslationsTest extends TestCase
{
    public function testRuAndEnDictionariesHaveTheSameKeys(): void
    {
        $translationsDir = \dirname(__DIR__, 3).'/translations';

        $ruCatalog = TranslationCatalog::loadFile($translationsDir.'/messages.ru.yaml');
        $enCatalog = TranslationCatalog::loadFile($translationsDir.'/messages.en.yaml');

        $this->assertNotNull($ruCatalog, 'messages.ru.yaml is missing or failed to parse.');
        $this->assertNotNull($enCatalog, 'messages.en.yaml is missing or failed to parse.');

        $ru = array_keys($ruCatalog);
        $en = array_keys($enCatalog);

        sort($ru);
        sort($en);

        $this->assertSame($ru, $en);
    }
}
