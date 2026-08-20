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

final class BaseTemplateRenderingTest extends KernelTestCase
{
    public function testBaseTemplateRendersWithoutErrors(): void
    {
        self::bootKernel();

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push(Request::create('/'));

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('base.html.twig');

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('AnimeDB', $html);
        $this->assertStringContainsString('<html lang="en" dir="ltr">', $html);
    }

    /**
     * The RTL direction comes from a static table in core (issue #450), not from a translation
     * plugin, so it must switch with the locale even though no RTL plugin is installed in this
     * test suite.
     */
    public function testHtmlDirSwitchesToRtlForAnRtlLocaleWithoutAnyTranslationPlugin(): void
    {
        self::bootKernel();

        $request = Request::create('/');
        $request->setLocale('ar');

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('base.html.twig');

        $this->assertStringContainsString('<html lang="ar" dir="rtl">', $html);
    }
}
