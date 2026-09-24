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

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * plugin/_widget_slots.html.twig (issue #720) is shared by anime/show.html.twig (entry widgets,
 * entryId given) and anime/list.html.twig (catalog widgets, no entryId) — pins that the
 * `plugin_widget` route URL it builds carries `entryId` only when one is passed in.
 */
final class WidgetSlotsTemplateRenderingTest extends KernelTestCase
{
    public function testSlotUrlIncludesEntryIdWhenGiven(): void
    {
        self::bootKernel();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('plugin/_widget_slots.html.twig', [
            'widgets' => [['pluginId' => 'animedb-shikimori', 'widgetName' => 'related', 'title' => 'Related', 'pluginName' => 'Shikimori']],
            'entryId' => 42,
        ]);

        $this->assertStringContainsString('hx-get="/plugin/animedb-shikimori/widget/related?entryId=42"', $html);
        $this->assertStringContainsString('hx-trigger="load"', $html);
        $this->assertStringContainsString('class="plugin-widget"', $html);
    }

    public function testSlotUrlOmitsEntryIdWhenNotGiven(): void
    {
        self::bootKernel();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('plugin/_widget_slots.html.twig', [
            'widgets' => [['pluginId' => 'animedb-shikimori', 'widgetName' => 'top', 'title' => 'Top', 'pluginName' => 'Shikimori']],
        ]);

        $this->assertStringContainsString('hx-get="/plugin/animedb-shikimori/widget/top"', $html);
        $this->assertStringNotContainsString('entryId', $html);
    }

    public function testRendersNothingForAnEmptyWidgetList(): void
    {
        self::bootKernel();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('plugin/_widget_slots.html.twig', ['widgets' => []]);

        $this->assertSame('', trim($html));
    }

    /**
     * Issue #728: a slot's header must name both the widget and the plugin behind it — the
     * whole point being that a widget's carousel is never mistaken for the user's own catalog.
     */
    public function testSlotRendersAHeaderWithTheWidgetTitleAndThePluginName(): void
    {
        self::bootKernel();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('plugin/_widget_slots.html.twig', [
            'widgets' => [['pluginId' => 'animedb-shikimori', 'widgetName' => 'new_releases', 'title' => 'New releases', 'pluginName' => 'Shikimori']],
        ]);

        $this->assertStringContainsString('class="plugin-widget-slot__heading"', $html);
        $this->assertStringContainsString('New releases', $html);
        $this->assertStringContainsString('Shikimori', $html);

        // The header must sit ahead of the hx-get placeholder inside the same slot, not after it.
        $headingPosition = strpos($html, 'plugin-widget-slot__heading');
        $placeholderPosition = strpos($html, 'hx-get=');
        $this->assertNotFalse($headingPosition);
        $this->assertNotFalse($placeholderPosition);
        $this->assertLessThan($placeholderPosition, $headingPosition);
    }

    /**
     * The registry already falls back to `widgetName` when `titleKey` has no translation (see
     * CatalogWidgetRegistryTest/EntryWidgetRegistryTest) — this pins that the template simply
     * displays whatever it is given, so a raw translation key never reaches this far either.
     */
    public function testSlotHeaderShowsTheGivenTitleAsIsWithoutReResolvingIt(): void
    {
        self::bootKernel();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('plugin/_widget_slots.html.twig', [
            'widgets' => [['pluginId' => 'animedb-shikimori', 'widgetName' => 'new_releases', 'title' => 'new_releases', 'pluginName' => 'animedb-shikimori']],
        ]);

        $this->assertStringNotContainsString('widget.new_releases.title', $html);
        $this->assertStringContainsString('new_releases', $html);
    }
}
