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

namespace App\Tests\Unit\Service\Market;

use App\Service\Market\Exception\InvalidMarketSnapshotContentException;
use App\Service\Market\MarketSnapshotPlugin;
use PHPUnit\Framework\TestCase;

final class MarketSnapshotPluginTest extends TestCase
{
    /**
     * @param list<string>|null $locales
     */
    private function plugin(?int $translationKeyCount, ?array $locales = null): MarketSnapshotPlugin
    {
        return new MarketSnapshotPlugin(
            'animedb-german',
            ['id' => 'animedb-german', 'name' => 'German'],
            '1.1.0',
            'sha-1.1.0',
            '1.2.0',
            '>=3.0.0',
            $translationKeyCount,
            $locales,
        );
    }

    public function testToArrayCarriesTheTranslationKeyCount(): void
    {
        $this->assertSame(120, $this->plugin(120)->toArray()['translationKeyCount']);
    }

    public function testFromArrayRoundTripsTheTranslationKeyCount(): void
    {
        $restored = MarketSnapshotPlugin::fromArray($this->plugin(120)->toArray());

        $this->assertSame(120, $restored->translationKeyCount);
    }

    /**
     * Issue #514's backward-compatibility acceptance criterion: a snapshot cached before this
     * field existed has no `translationKeyCount` key at all, not an explicit `null` — the loader
     * must not treat "key entirely absent" as malformed data, or every pre-existing cache file
     * would fail to load until the next refresh.
     */
    public function testFromArrayTreatsAMissingTranslationKeyCountAsNull(): void
    {
        $data = $this->plugin(120)->toArray();
        unset($data['translationKeyCount']);

        $restored = MarketSnapshotPlugin::fromArray($data);

        $this->assertNull($restored->translationKeyCount);
    }

    public function testFromArrayAcceptsAnExplicitNullTranslationKeyCount(): void
    {
        $restored = MarketSnapshotPlugin::fromArray($this->plugin(null)->toArray());

        $this->assertNull($restored->translationKeyCount);
    }

    public function testFromArrayRejectsANonIntegerTranslationKeyCount(): void
    {
        $data = $this->plugin(120)->toArray();
        $data['translationKeyCount'] = 'not-a-number';

        $this->expectException(InvalidMarketSnapshotContentException::class);
        MarketSnapshotPlugin::fromArray($data);
    }

    public function testToArrayCarriesTheLocales(): void
    {
        $this->assertSame(['de', 'ja'], $this->plugin(120, ['de', 'ja'])->toArray()['locales']);
    }

    public function testFromArrayRoundTripsTheLocales(): void
    {
        $restored = MarketSnapshotPlugin::fromArray($this->plugin(120, ['de', 'ja'])->toArray());

        $this->assertSame(['de', 'ja'], $restored->locales);
    }

    /**
     * Issue #543's backward-compatibility acceptance criterion: a snapshot cached before this
     * field existed has no `locales` key at all, not an explicit `null` — the loader must not
     * treat "key entirely absent" as malformed data, or every pre-existing cache file would fail
     * to load until the next refresh.
     */
    public function testFromArrayTreatsAMissingLocalesAsNull(): void
    {
        $data = $this->plugin(120, ['de', 'ja'])->toArray();
        unset($data['locales']);

        $restored = MarketSnapshotPlugin::fromArray($data);

        $this->assertNull($restored->locales);
    }

    public function testFromArrayAcceptsAnExplicitNullLocales(): void
    {
        $restored = MarketSnapshotPlugin::fromArray($this->plugin(120, null)->toArray());

        $this->assertNull($restored->locales);
    }

    public function testFromArrayRejectsANonListLocales(): void
    {
        $data = $this->plugin(120, ['de', 'ja'])->toArray();
        $data['locales'] = 'de';

        $this->expectException(InvalidMarketSnapshotContentException::class);
        MarketSnapshotPlugin::fromArray($data);
    }
}
