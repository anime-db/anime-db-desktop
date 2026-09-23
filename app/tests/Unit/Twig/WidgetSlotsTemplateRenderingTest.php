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
            'widgets' => [['pluginId' => 'animedb-shikimori', 'widgetName' => 'related']],
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
            'widgets' => [['pluginId' => 'animedb-shikimori', 'widgetName' => 'top']],
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
}
