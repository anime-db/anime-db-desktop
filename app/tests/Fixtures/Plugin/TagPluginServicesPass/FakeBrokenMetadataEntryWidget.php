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

namespace AnimeDb\Plugins\FakeVendor;

use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\PluginContracts\Widget\EntryWidgetInterface;
use AnimeDb\PluginContracts\Widget\WidgetMetadata;

/**
 * Fixture used by TagPluginServicesPassTest: an entry widget whose `metadata()` throws, to prove
 * the pass under test skips it with a log entry instead of failing the whole container build
 * (issue #364) — a plugin's `metadata()` is untrusted third-party code.
 */
final class FakeBrokenMetadataEntryWidget implements EntryWidgetInterface
{
    public static function metadata(): WidgetMetadata
    {
        throw new \RuntimeException('Broken metadata() fixture.');
    }

    public function resolveExternalId(array $urls): ?string
    {
        return null;
    }

    public function render(AnimeId $anime): string
    {
        return '';
    }
}
