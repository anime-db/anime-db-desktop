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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * Issue #720: an active catalog widget had no slot anywhere on the catalog page before this.
 * These pin the actual anime/list.html.twig markup — not just plugin/_widget_slots.html.twig in
 * isolation (see WidgetSlotsTemplateRenderingTest) — so a future change to the surrounding page
 * cannot silently drop the widgets row again.
 */
final class AnimeListTemplateRenderingTest extends KernelTestCase
{
    /** base.html.twig reads app.request.locale, so a real request cycle needs one on the stack. */
    private function pushRequest(): void
    {
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push(Request::create('/'));
    }

    public function testRendersACatalogWidgetSlotWithoutAnEntryIdWhenAWidgetIsActive(): void
    {
        self::bootKernel();
        $this->pushRequest();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/list.html.twig', [
            'showOnboarding' => false,
            'widgets' => [['pluginId' => 'animedb-shikimori', 'widgetName' => 'spotlight']],
        ]);

        $this->assertStringContainsString('anime-list__widgets', $html);
        $this->assertStringContainsString('hx-get="/plugin/animedb-shikimori/widget/spotlight"', $html);
        $this->assertStringNotContainsString('entryId', $html);

        // The widgets row must sit ahead of the chips/grid, inside the main column - see the
        // issue's "Место слота" section for why (infinite scroll makes anything below the grid
        // unreachable, and the filters sidebar is a fixed-width area with a different role).
        $widgetsPosition = strpos($html, 'anime-list__widgets');
        $chipsPosition = strpos($html, 'anime-list-chips');
        $gridPosition = strpos($html, 'anime-list-grid');
        $this->assertNotFalse($widgetsPosition);
        $this->assertNotFalse($chipsPosition);
        $this->assertNotFalse($gridPosition);
        $this->assertLessThan($chipsPosition, $widgetsPosition);
        $this->assertLessThan($gridPosition, $widgetsPosition);
    }

    public function testOmitsTheWidgetsRowWhenNoCatalogWidgetIsActive(): void
    {
        self::bootKernel();
        $this->pushRequest();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/list.html.twig', [
            'showOnboarding' => false,
            'widgets' => [],
        ]);

        $this->assertStringNotContainsString('anime-list__widgets', $html);
    }
}
