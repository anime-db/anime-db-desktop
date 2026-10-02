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
            'hasScannableStorages' => true,
        ]);

        $this->assertStringContainsString('action="/storage/7/scan"', $html);
        $this->assertStringContainsString('Main folder', $html);
        $this->assertStringContainsString('2 more not connected', $html);
        $this->assertStringNotContainsString('href="/storage/new"', $html);
    }

    public function testRendersTheAddStorageFallbackWhenNoneAreScannable(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('nav/_add_menu_scan_section.html.twig', [
            'connectedStorages' => [],
            'disconnectedCount' => 0,
            'hasScannableStorages' => false,
        ]);

        $this->assertStringContainsString('href="/storage/new"', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('not connected', $html);
    }
}
