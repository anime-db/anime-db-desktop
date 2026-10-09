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

use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Acceptance (issue #834): nav/_add_menu_scan_section.html.twig renders through the real
 * container — {@see \App\Tests\Unit\Controller\NavControllerTest} pins what NavController passes
 * it, this test pins that the template itself turns those params into valid markup (one `<li>`
 * per connected storage, the "N more" line, the "Add storage…" fallback).
 */
final class NavAddMenuScanSectionTemplateRenderingTest extends KernelTestCase
{
    private function pushRequestWithSession(): void
    {
        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage()));

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);
    }

    private function storage(int $id, string $name): Storage
    {
        $storage = new Storage($name, 'D:\\Anime', StorageType::Folder);
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($storage, $id);

        return $storage;
    }

    public function testRendersOneFormPerConnectedStorageAndTheDisconnectedCount(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('nav/_add_menu_scan_section.html.twig', [
            'connectedStorages' => [$this->storage(7, 'Main folder')],
            'disconnectedCount' => 2,
        ]);

        $this->assertStringContainsString('action="/storage/7/scan"', $html);
        $this->assertStringContainsString('Main folder', $html);
        $this->assertStringContainsString('Not connected: 2', $html);
        $this->assertStringNotContainsString('href="/storage/new"', $html);
    }

    /**
     * Regression (issue #834 review): each storage's "Scan" button must carry *its own*
     * `storage_scan_{id}` CSRF token — StorageController::scan() validates against that exact
     * token id, so a template regression that hardcoded a single shared token (e.g.
     * `csrf_token('storage_scan')`) would pass {@see self::testRendersOneFormPerConnectedStorageAndTheDisconnectedCount()}
     * (which never looks at the token value) yet make every scan from this menu fail CSRF
     * validation silently.
     */
    public function testEachStorageFormCarriesItsOwnCsrfToken(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('nav/_add_menu_scan_section.html.twig', [
            'connectedStorages' => [$this->storage(7, 'Main folder'), $this->storage(9, 'Backup folder')],
            'disconnectedCount' => 0,
        ]);

        $tokenForStorage7 = $this->extractTokenAfter($html, 'action="/storage/7/scan"');
        $tokenForStorage9 = $this->extractTokenAfter($html, 'action="/storage/9/scan"');

        /** @var CsrfTokenManagerInterface $csrfTokenManager */
        $csrfTokenManager = self::getContainer()->get(CsrfTokenManagerInterface::class);

        // Each token must validate against its own storage's token id...
        $this->assertTrue($csrfTokenManager->isTokenValid(new CsrfToken('storage_scan_7', $tokenForStorage7)));
        $this->assertTrue($csrfTokenManager->isTokenValid(new CsrfToken('storage_scan_9', $tokenForStorage9)));
        // ...and must not validate against the other storage's id, or a single shared id a
        // template regression could have hardcoded instead of interpolating the storage's own id.
        $this->assertFalse($csrfTokenManager->isTokenValid(new CsrfToken('storage_scan_9', $tokenForStorage7)));
        $this->assertFalse($csrfTokenManager->isTokenValid(new CsrfToken('storage_scan', $tokenForStorage7)));
    }

    private function extractTokenAfter(string $html, string $formActionNeedle): string
    {
        $formStart = strpos($html, $formActionNeedle);
        self::assertNotFalse($formStart, \sprintf('Expected to find a form with %s.', $formActionNeedle));

        $matched = preg_match('/name="_token" value="([^"]+)"/', $html, $matches, 0, $formStart);
        self::assertSame(1, $matched, \sprintf('Expected a CSRF token field after %s.', $formActionNeedle));

        return $matches[1];
    }

    public function testDoesNotRenderAnAddStorageItemOfItsOwnSinceTheMenuHasAStaticOne(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('nav/_add_menu_scan_section.html.twig', [
            'connectedStorages' => [],
            'disconnectedCount' => 0,
        ]);

        $this->assertStringNotContainsString('/storage/new', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('Not connected', $html);
        $this->assertStringContainsString('No storages to scan', $html);
    }

    public function testDoesNotRenderThePlaceholderWhenThereAreStoragesToScan(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        foreach ([[[$this->storage(7, 'Main folder')], 0], [[], 2]] as [$connected, $disconnected]) {
            $html = $twig->render('nav/_add_menu_scan_section.html.twig', [
                'connectedStorages' => $connected,
                'disconnectedCount' => $disconnected,
            ]);

            $this->assertStringNotContainsString('No storages to scan', $html);
        }
    }
}
