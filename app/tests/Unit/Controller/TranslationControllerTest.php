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

use App\Controller\TranslationController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\TranslatorBagInterface;

final class TranslationControllerTest extends TestCase
{
    public function testInvokeReturnsCatalogueForKnownLocale(): void
    {
        $catalogue = new MessageCatalogue('ru', [
            'messages' => ['genre.action' => 'Экшен'],
        ]);

        $translator = $this->createMock(TranslatorBagInterface::class);
        $translator->expects($this->once())
            ->method('getCatalogue')
            ->with('ru')
            ->willReturn($catalogue);

        $controller = new TranslationController(['en', 'ru'], $translator);
        $response = $controller('ru');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['genre.action' => 'Экшен'],
            json_decode((string) $response->getContent(), true),
        );
    }

    public function testInvokeRejectsUnknownLocale(): void
    {
        $translator = $this->createMock(TranslatorBagInterface::class);
        $translator->expects($this->never())->method('getCatalogue');

        $controller = new TranslationController(['en', 'ru'], $translator);

        $this->expectException(NotFoundHttpException::class);
        $controller('fr');
    }
}
