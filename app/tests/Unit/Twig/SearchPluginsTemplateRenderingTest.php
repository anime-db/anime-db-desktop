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
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * Issue #833 review: pins two accessibility/behaviour requirements for the "search in plugins"
 * screen's own templates that nothing else in the suite asserts on directly — a candidate must
 * be a real `<button>` (keyboard/AT operable, not a plain clickable `<li>`), and the preview
 * panel's live region must announce every swap, including an empty one.
 */
final class SearchPluginsTemplateRenderingTest extends KernelTestCase
{
    private function pushRequest(): void
    {
        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage()));

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);
    }

    public function testGroupRendersEachCandidateAsAButton(): void
    {
        self::bootKernel();
        $this->pushRequest();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/search_plugins/_group.html.twig', [
            'pluginId' => 'animedb-shikimori',
            'pluginName' => 'Shikimori',
            'state' => 'results',
            'candidates' => [['name' => 'Trigun', 'externalId' => '1']],
        ]);

        $this->assertStringContainsString('<button', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    public function testIndexPreviewPanelHasAnAriaLiveRegion(): void
    {
        self::bootKernel();
        $this->pushRequest();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/search_plugins/index.html.twig', [
            'query' => '',
            'hasActiveFiller' => true,
            'noFillerState' => null,
            'error' => null,
        ]);

        $this->assertMatchesRegularExpression(
            '/<aside[^>]*id="search-plugins-preview"[^>]*aria-live="polite"/',
            $html,
        );
    }
}
