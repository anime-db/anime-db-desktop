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

use App\Entity\Anime;
use App\Entity\Enum\ProxyMode;
use App\Entity\Enum\ProxyProtocol;
use App\Entity\Enum\ProxyTestOutcome;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\Label;
use App\Entity\SyncReviewItem;
use App\Entity\TvAnime;
use App\Entity\ValueObject\ProxySettings;
use App\Entity\ValueObject\ProxyTestResult;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Translation\LocaleSwitcher;
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

    /**
     * Isolates just the incoming-connections checkbox's own markup — the page also renders the
     * proxy mode radio group, which already has its own "checked"/no-"disabled" state and would
     * otherwise make a plain assertStringContainsString('checked', $html) pass for the wrong
     * reason.
     */
    private function extractIncomingConnectionsCheckbox(string $html): string
    {
        $matched = preg_match('/<input[^>]*name="enabled"[^>]*>/s', $html, $matches);
        $this->assertSame(1, $matched, 'Expected the incoming-connections checkbox to be present.');

        return $matches[0];
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
            'reindexStatus' => null,
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
            'reindexStatus' => null,
        ]);

        $this->assertStringContainsString('<option value="en" selected>English</option>', $html);
        $this->assertStringContainsString('<option value="ru">Русский</option>', $html);
    }

    public function testSettingsIndexRendersReindexSuccessMessage(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/index.html.twig', [
            'availableLocales' => ['en', 'ru'],
            'currentLocale' => 'ru',
            'reindexStatus' => 'success',
        ]);

        $this->assertStringContainsString('Поисковый индекс успешно перестроен.', $html);
    }

    public function testSettingsIndexRendersReindexErrorMessage(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/index.html.twig', [
            'availableLocales' => ['en', 'ru'],
            'currentLocale' => 'ru',
            'reindexStatus' => 'error',
        ]);

        $this->assertStringContainsString('Не удалось перестроить поисковый индекс.', $html);
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
        // Direct Twig::render() bypasses kernel.request, so the built-in LocaleAwareListener
        // never syncs the translator locale from the request the way it does on a real request
        // (see LocaleSubscriber, issue #84) — set it explicitly to assert a specific locale.
        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/label/index.html.twig', ['labels' => [], 'error' => 'empty_name']);

        $this->assertStringContainsString('Меток пока нет.', $html);
        $this->assertStringContainsString('Имя метки не может быть пустым.', $html);
    }

    public function testSyncReviewIndexRendersEmptyStateWithoutErrors(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();
        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/sync_review/index.html.twig', ['items' => [], 'duplicateClusters' => []]);

        $this->assertStringContainsString('Нет элементов, требующих внимания.', $html);
    }

    public function testSyncReviewIndexRendersDuplicateClusterWithLinksToAnimeCards(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();
        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        $item = new SyncReviewItem(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => [1, 2]]);
        (new \ReflectionProperty(SyncReviewItem::class, 'id'))->setValue($item, 1);

        $anime = new TvAnime();
        $anime->setTitle('Trigun');
        (new \ReflectionProperty(Anime::class, 'id'))->setValue($anime, 1);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/sync_review/index.html.twig', [
            'items' => [$item],
            'duplicateClusters' => [1 => [$anime]],
        ]);

        $this->assertStringContainsString('Trigun', $html);
        $this->assertStringContainsString('/anime/1', $html);
        $this->assertStringContainsString('Возможный дубликат', $html);
    }

    public function testProxyIndexRendersWithoutErrors(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();
        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Socks5, 'proxy.example', 1080);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/proxy/index.html.twig', ['settings' => $settings, 'saved' => false]);

        $this->assertStringContainsString('Прокси-сервер', $html);
        $this->assertStringContainsString('proxy.example', $html);
    }

    public function testProxyIndexRendersSaveSuccessMessage(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();
        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        $settings = new ProxySettings(ProxyMode::None, ProxyProtocol::Socks5, null, null);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/proxy/index.html.twig', ['settings' => $settings, 'saved' => true]);

        $this->assertStringContainsString('Настройки прокси сохранены.', $html);
    }

    public function testProxyIndexRendersTorrentProxyErrorMessage(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();
        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Socks5, 'proxy.example', 1080);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/proxy/index.html.twig', [
            'settings' => $settings,
            'saved' => true,
            'torrentProxyError' => true,
        ]);

        $this->assertStringContainsString('не удалось применить SOCKS5-прокси', $html);
    }

    public function testProxyIndexHidesIncomingConnectionsSectionWhenNotWindows(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();
        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        $settings = new ProxySettings(ProxyMode::None, ProxyProtocol::Socks5, null, null);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/proxy/index.html.twig', [
            'settings' => $settings,
            'saved' => false,
            'isWindows' => false,
            'incomingConnectionsAllowed' => false,
            'incomingConnectionsEligible' => true,
        ]);

        $this->assertStringNotContainsString('name="enabled"', $html);
    }

    public function testProxyIndexRendersIncomingConnectionsToggleCheckedAndEnabledInDirectMode(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();
        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        $settings = new ProxySettings(ProxyMode::None, ProxyProtocol::Socks5, null, null);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/proxy/index.html.twig', [
            'settings' => $settings,
            'saved' => false,
            'isWindows' => true,
            'incomingConnectionsAllowed' => true,
            'incomingConnectionsEligible' => true,
        ]);

        $checkbox = $this->extractIncomingConnectionsCheckbox($html);
        $this->assertStringContainsString('checked', $checkbox);
        $this->assertStringNotContainsString('disabled', $checkbox);
    }

    public function testProxyIndexDisablesIncomingConnectionsToggleWhenSocks5ActiveAndNotAlreadyAllowed(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();
        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Socks5, 'proxy.example', 1080);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/proxy/index.html.twig', [
            'settings' => $settings,
            'saved' => false,
            'isWindows' => true,
            'incomingConnectionsAllowed' => false,
            'incomingConnectionsEligible' => false,
        ]);

        $checkbox = $this->extractIncomingConnectionsCheckbox($html);
        $this->assertStringContainsString('disabled', $checkbox);
        $this->assertStringNotContainsString('checked', $checkbox);
    }

    public function testProxyIndexKeepsIncomingConnectionsToggleEnabledWhenAlreadyAllowedDespiteSocks5(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();
        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Socks5, 'proxy.example', 1080);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/proxy/index.html.twig', [
            'settings' => $settings,
            'saved' => false,
            'isWindows' => true,
            'incomingConnectionsAllowed' => true,
            'incomingConnectionsEligible' => false,
        ]);

        $checkbox = $this->extractIncomingConnectionsCheckbox($html);
        $this->assertStringContainsString('checked', $checkbox);
        $this->assertStringNotContainsString('disabled', $checkbox);
    }

    public function testProxyIndexRendersIncomingConnectionsErrorMessage(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();
        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Socks5, 'proxy.example', 1080);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/proxy/index.html.twig', [
            'settings' => $settings,
            'saved' => false,
            'isWindows' => true,
            'incomingConnectionsAllowed' => false,
            'incomingConnectionsEligible' => false,
            'incomingConnectionsError' => true,
        ]);

        $this->assertStringContainsString('Недоступно при выбранном SOCKS5-прокси.', $html);
    }

    public function testProxyTestResultFragmentRendersSuccessWithoutErrors(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();
        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/proxy/_test_result.html.twig', ['result' => ProxyTestResult::success(120)]);

        $this->assertStringContainsString('120', $html);
    }

    public function testProxyTestResultFragmentRendersFailureWithoutErrors(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();
        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/proxy/_test_result.html.twig', [
            'result' => ProxyTestResult::failure(ProxyTestOutcome::AuthFailed),
        ]);

        $this->assertStringContainsString('Ошибка аутентификации на прокси-сервере.', $html);
    }
}
