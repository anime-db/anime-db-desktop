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

namespace App\Tests\Unit\Controller;

use App\Service\Settings\SettingsNavigationService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Acceptance (issue #822): every GET route under `/settings` and `/storage` must map to exactly
 * one sidebar item — {@see SettingsNavigationService::ROUTE_ITEM_MAP} is an array keyed by route
 * name, so a route present in it is mechanically mapped to exactly one item id. `settings_plugin_page`
 * is the one deliberate exception within scope: it is resolved by `pluginId`, not by this static
 * table (see the service's own docblock). The routes below are excluded for the reasons given in
 * the issue: `storage_scan_prompt` and `storage_scan_progress` (issue #834) both stay on the base
 * layout (a catalog workflow, not a settings page), and the rest are JSON/status endpoints with no
 * page to highlight a sidebar item on.
 */
final class SettingsNavigationRouteMapTest extends KernelTestCase
{
    private const array EXCLUDED_ROUTES = [
        'storage_scan_prompt',
        'storage_scan_progress',
        'settings_market_refresh_status',
        'storage_paths',
    ];

    public function testEveryGetRouteUnderSettingsAndStorageMapsToExactlyOneSidebarItem(): void
    {
        self::bootKernel();

        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');

        $unmapped = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            if (\in_array($name, self::EXCLUDED_ROUTES, true) || $name === 'settings_plugin_page') {
                continue;
            }

            $methods = $route->getMethods();
            $isGet = $methods === [] || \in_array('GET', $methods, true);
            $path = $route->getPath();

            if (!$isGet || (!str_starts_with($path, '/settings') && !str_starts_with($path, '/storage'))) {
                continue;
            }

            if (!\array_key_exists($name, SettingsNavigationService::ROUTE_ITEM_MAP)) {
                $unmapped[] = $name;
            }
        }

        self::assertSame([], $unmapped, 'Unmapped GET routes under /settings or /storage: '.implode(', ', $unmapped));
    }

    /**
     * Pins the exclusion list itself: each excluded route must actually exist as a GET route under
     * `/settings` or `/storage` (otherwise the exclusion is dead and the test above would not have
     * caught a route rename), and must genuinely be absent from the map.
     */
    public function testExcludedRoutesExistAndStayUnmapped(): void
    {
        self::bootKernel();

        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');
        $collection = $router->getRouteCollection();

        foreach (self::EXCLUDED_ROUTES as $name) {
            $route = $collection->get($name);
            self::assertNotNull($route, \sprintf('Excluded route "%s" no longer exists.', $name));

            $methods = $route->getMethods();
            self::assertTrue($methods === [] || \in_array('GET', $methods, true), \sprintf('Excluded route "%s" is expected to be a GET route.', $name));

            self::assertArrayNotHasKey($name, SettingsNavigationService::ROUTE_ITEM_MAP);
        }
    }
}
