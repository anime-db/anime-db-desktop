<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Entity\Label;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

final class SettingsTemplateRenderingTest extends KernelTestCase
{
    private function createPersistedLabel(int $id, string $name): Label
    {
        $label = new Label();
        $label->rename($name);
        (new \ReflectionProperty(Label::class, 'id'))->setValue($label, $id);

        return $label;
    }

    /**
     * csrf_token() reads/writes the CSRF token through the session of the current request, so
     * rendering a template that calls it outside a real HTTP request-response cycle needs one
     * pushed onto the request stack manually.
     */
    private function pushRequestWithSession(): void
    {
        $request = Request::create('/settings/labels');
        $request->setSession(new Session(new MockArraySessionStorage()));

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);
    }

    public function testSettingsIndexRendersWithoutErrors(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/index.html.twig', [
            'availableLocales' => ['en', 'ru'],
            'currentLocale' => 'ru',
        ]);

        $this->assertStringContainsString('Настройки', $html);
        $this->assertStringContainsString('/settings/labels', $html);
    }

    public function testSettingsIndexRendersLocaleSwitcherWithCurrentLocaleSelected(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/index.html.twig', [
            'availableLocales' => ['en', 'ru'],
            'currentLocale' => 'en',
        ]);

        $this->assertStringContainsString('<option value="en" selected>English</option>', $html);
        $this->assertStringContainsString('<option value="ru">Русский</option>', $html);
    }

    public function testLabelIndexRendersLabelsWithoutErrors(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        $label = $this->createPersistedLabel(1, 'favorite');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/label/index.html.twig', ['labels' => [$label], 'error' => null]);

        $this->assertStringContainsString('favorite', $html);
    }

    public function testLabelIndexRendersEmptyStateWithoutErrors(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/label/index.html.twig', ['labels' => [], 'error' => 'empty_name']);

        $this->assertStringContainsString('Меток пока нет.', $html);
        $this->assertStringContainsString('Имя метки не может быть пустым.', $html);
    }
}
