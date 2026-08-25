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

use App\Service\Translation\PlaceholderParity;
use App\Service\Translation\TranslationCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Guards against a translation defect that the key-parity check in CatalogTranslationsTest
 * cannot see: a key present in both locales whose %placeholder% got lost or renamed in
 * translation. The key set matching is not enough on its own — the string content matters too.
 */
final class CatalogPlaceholderParityTest extends TestCase
{
    public function testCatalogValuesDoNotUseForbiddenSyntax(): void
    {
        $failures = [];
        foreach ($this->loadCatalogs() as $locale => $catalog) {
            foreach ($catalog as $key => $value) {
                $forbidden = PlaceholderParity::findForbiddenCharacters($value);

                if ($forbidden !== []) {
                    $failures[] = sprintf(
                        'messages.%s.yaml key "%s" uses reserved syntax "%s". This project uses '
                        .'only %%name%% placeholders and does not use pluralization/ICU catalogs.',
                        $locale,
                        $key,
                        implode('", "', $forbidden),
                    );
                }
            }
        }

        $this->assertSame([], $failures, "Forbidden syntax in translation catalogs:\n".implode("\n", $failures));
    }

    public function testPlaceholdersMatchAcrossLocalesForSharedKeys(): void
    {
        $catalogs = $this->loadCatalogs();

        $locales = array_keys($catalogs);
        sort($locales);
        $reference = $locales[0];

        $failures = [];
        foreach ($locales as $locale) {
            if ($locale === $reference) {
                continue;
            }

            foreach ($catalogs[$reference] as $key => $referenceValue) {
                if (!\array_key_exists($key, $catalogs[$locale])) {
                    // Key parity is covered by CatalogTranslationsTest, not here.
                    continue;
                }

                $diff = PlaceholderParity::diff($referenceValue, $catalogs[$locale][$key]);

                if ($diff !== null) {
                    $failures[] = sprintf(
                        '%s (%s vs %s): missing [%s], extra [%s]',
                        $key,
                        $reference,
                        $locale,
                        implode(', ', $diff['missing']),
                        implode(', ', $diff['extra']),
                    );
                }
            }
        }

        $this->assertSame([], $failures, "Placeholder mismatch between locales:\n".implode("\n", $failures));
    }

    /**
     * @return array<string, array<string, string>> locale => flattened dot-key => value
     */
    private function loadCatalogs(): array
    {
        $translationsDir = \dirname(__DIR__, 3).'/translations';

        $catalogs = [];
        foreach (glob($translationsDir.'/messages.*.yaml') ?: [] as $file) {
            if (preg_match('/^messages\.([a-zA-Z_-]+)\.yaml$/', basename($file), $matches) !== 1) {
                $this->fail(sprintf(
                    'Translation catalog "%s" does not match the expected "messages.<locale>.yaml" naming '
                    .'pattern and was excluded from the placeholder parity check.',
                    basename($file),
                ));
            }

            $catalog = TranslationCatalog::loadFile($file);
            if ($catalog === null) {
                $this->fail(sprintf('Translation catalog "%s" is missing or failed to parse.', basename($file)));
            }

            $catalogs[$matches[1]] = $catalog;
        }

        return $catalogs;
    }
}
