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

namespace App\Tests\Unit\Controller\Settings;

use App\Controller\Settings\SearchIndexController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

final class SearchIndexControllerTest extends TestCase
{
    /**
     * The `?status=` query parameter arrives unvalidated from the POST-Redirect-GET flow
     * (issue #822) — this is the only logic on this controller's input boundary, so it is the
     * only thing worth asserting: a whitelisted value passes through, anything else (including no
     * parameter at all) is discarded as `null` rather than rendered verbatim.
     */
    public function testStatusSuccessIsPassedThroughToTheTemplate(): void
    {
        $this->assertReindexStatus('success', 'success');
    }

    public function testStatusErrorIsPassedThroughToTheTemplate(): void
    {
        $this->assertReindexStatus('error', 'error');
    }

    public function testAnUnrecognizedStatusValueIsDiscardedAsNull(): void
    {
        $this->assertReindexStatus('<script>alert(1)</script>', null);
    }

    public function testAMissingStatusParameterIsNull(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/search_index/index.html.twig', ['reindexStatus' => null])
            ->willReturn('<html></html>');

        $controller = new SearchIndexController($twig);
        $response = $controller->__invoke(Request::create('/settings/search-index'));

        $this->assertSame(200, $response->getStatusCode());
    }

    private function assertReindexStatus(string $queryValue, ?string $expected): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/search_index/index.html.twig', ['reindexStatus' => $expected])
            ->willReturn('<html></html>');

        $controller = new SearchIndexController($twig);
        $response = $controller->__invoke(Request::create('/settings/search-index?status='.urlencode($queryValue)));

        $this->assertSame(200, $response->getStatusCode());
    }
}
