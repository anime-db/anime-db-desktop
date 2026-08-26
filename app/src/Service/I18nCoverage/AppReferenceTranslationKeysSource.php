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

namespace App\Service\I18nCoverage;

use App\Service\Translation\TranslationCatalog;
use App\Service\Translation\TranslationCoverageService;

/**
 * Real {@see ApplicationTranslationKeysSource}, reading the exact same reference file
 * {@see TranslationCoverageService} itself reads (issues #512/#514) via the same
 * {@see TranslationCatalog} helper — there is no second YAML-catalog reader here, only a second
 * caller of the one that already exists.
 *
 * `count(self::keys())` and `TranslationCoverageService::referenceKeyCount()` are therefore
 * guaranteed identical (both flatten `translations/messages.en.yaml` through
 * {@see TranslationCatalog}): {@see \App\Tests\Unit\Service\I18nCoverage\AppReferenceTranslationKeysSourceTest}
 * asserts this against the real service rather than a fake, precisely to keep that guarantee from
 * silently breaking if either side is ever changed.
 */
final class AppReferenceTranslationKeysSource implements ApplicationTranslationKeysSource
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function keys(): array
    {
        return array_keys(TranslationCatalog::loadFile($this->referenceCatalogPath()) ?? []);
    }

    private function referenceCatalogPath(): string
    {
        return $this->projectDir.\DIRECTORY_SEPARATOR.'translations'.\DIRECTORY_SEPARATOR.'messages.en.yaml';
    }
}
