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

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RouterInterface;

/**
 * Regression coverage for issue #618: `/anime/{id}` (`anime_show`) was declared before
 * `/anime/new` (`anime_new`) in controller-registration order (alphabetical by file name,
 * `AnimeController.php` before `AnimeNewController.php`), and without a requirement on `{id}`
 * the route compiler could not rule out `anime_show` for the literal path `/anime/new`. At
 * compile time, Symfony's route dumper only places a static path into the matcher's
 * `$staticRoutes` fast-path table when no earlier-declared dynamic route can also match it;
 * since unconstrained `/anime/{id}` could match `/anime/new`, the path was compiled into the
 * regular (order-dependent) matching branch instead, where declaration order decided the
 * winner and `anime_show` matched first.
 *
 * This must resolve through the real, compiled service router (`router->match()`) rather than
 * `Symfony\Component\Routing\Matcher\TraceableUrlMatcher` (as `router:match`/`debug:router
 * --format` use under the hood): the traceable matcher walks the route collection in declaration
 * order and reports the first route whose pattern *could* match, which is a different algorithm
 * from the compiled matcher and would not have caught this defect.
 */
final class AnimeRoutingTest extends KernelTestCase
{
    public function testAnimeNewPathResolvesToAnimeNewRouteNotAnimeShow(): void
    {
        self::bootKernel();

        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $parameters = $router->match('/anime/new');

        self::assertSame('anime_new', $parameters['_route']);
    }

    public function testAnimeShowPathWithNonNumericIdIsNotFound(): void
    {
        self::bootKernel();

        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $this->expectException(ResourceNotFoundException::class);

        $router->match('/anime/not-a-number');
    }

    public function testAnimeShowPathWithNumericIdResolvesToAnimeShowRoute(): void
    {
        self::bootKernel();

        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $parameters = $router->match('/anime/12');

        self::assertSame('anime_show', $parameters['_route']);
        self::assertSame('12', $parameters['id']);
    }
}
