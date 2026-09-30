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

use App\Entity\Anime;
use App\Entity\Enum\PaginationMode;
use App\Entity\Enum\ProxyMode;
use App\Entity\Enum\ProxyProtocol;
use App\Entity\Enum\ProxyTestOutcome;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\Enum\ThemePreference;
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
     * pushed onto the request stack manually. $locale drives `app.request.locale`, which the
     * settings template's language switcher now reads for the "selected" option (issue #558)
     * instead of an explicitly passed `currentLocale` variable.
     */
    private function pushRequestWithSession(?string $locale = null): void
    {
        $request = Request::create('/settings/labels');
        $request->setSession(new Session(new MockArraySessionStorage()));
        if ($locale !== null) {
            $request->setLocale($locale);
        }

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
        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/index.html.twig', [
            'availableLocales' => ['en', 'ru'],
            'unavailableLocale' => null,
            'themePreference' => ThemePreference::System,
            'paginationMode' => PaginationMode::InfiniteScroll,
        ]);

        $this->assertStringContainsString('Настройки', $html);
        $this->assertStringContainsString('/settings/labels', $html);
    }

    public function testSettingsIndexRendersLocaleSwitcherWithCurrentLocaleSelected(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession(locale: 'en');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/index.html.twig', [
            'availableLocales' => ['en', 'ru'],
            'unavailableLocale' => null,
            'themePreference' => ThemePreference::System,
            'paginationMode' => PaginationMode::InfiniteScroll,
        ]);

        $this->assertStringContainsString('<option value="en" selected>English</option>', $html);
        // ICU spells the Russian endonym lower-case ("русский"); LocaleEndonymResolver
        // capitalizes it for a uniform switcher list. Changing this expectation to match ICU's
        // raw output would be a regression, not a fix.
        $this->assertStringContainsString('<option value="ru">Русский</option>', $html);
    }

    /**
     * Regression (issue #567): the test above requests 'en', which is simultaneously the first
     * entry of availableLocales and framework.yaml's default_locale, so it can't tell the
     * template's real `locale == app.request.locale` condition apart from a hardcoded
     * `locale == 'en'` or `locale == availableLocales|first`. Requesting 'ru' — neither the first
     * available locale nor the default_locale — makes both of those wrong conditions fail.
     */
    public function testSettingsIndexRendersLocaleSwitcherWithCurrentLocaleSelectedWhenItDiffersFromTheFirstAvailableLocaleAndTheDefaultLocale(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession(locale: 'ru');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/index.html.twig', [
            'availableLocales' => ['en', 'ru'],
            'unavailableLocale' => null,
            'themePreference' => ThemePreference::System,
            'paginationMode' => PaginationMode::InfiniteScroll,
        ]);

        $this->assertStringContainsString('<option value="ru" selected>Русский</option>', $html);
        $this->assertStringNotContainsString('<option value="en" selected>', $html);
    }

    /**
     * Acceptance (issue #558): a saved locale that dropped out of the available list must render
     * as a disabled, pre-selected placeholder option carrying its endonym — not silently fall
     * back to the first available locale's option being selected instead.
     */
    public function testSettingsIndexRendersUnavailableLocaleAsDisabledSelectedOption(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession(locale: 'en');

        // Direct Twig::render() bypasses kernel.request (see testLabelIndexRendersEmptyStateWithoutErrors
        // above), so the translator's own locale needs setting independently of $request->setLocale()
        // above, which only feeds app.request.locale for the "selected" comparison.
        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('en');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/index.html.twig', [
            'availableLocales' => ['en', 'ru'],
            'unavailableLocale' => 'de',
            'themePreference' => ThemePreference::System,
            'paginationMode' => PaginationMode::InfiniteScroll,
        ]);

        $this->assertStringContainsString('<option value="" disabled selected>Deutsch — unavailable</option>', $html);
        $this->assertStringNotContainsString('<option value="en" selected>', $html);
        $this->assertStringNotContainsString('<option value="ru" selected>', $html);
    }

    /**
     * Acceptance (issue #461): a locale core has no translation catalog entry for at all — the
     * kind {@see \App\Service\Plugin\AvailableLocalesProvider} can add from an enabled
     * translation plugin — must render its endonym, not a raw `settings.locale.de` translation
     * key and not an empty label.
     */
    public function testSettingsIndexRendersEndonymForALocaleWithNoCoreCatalogEntry(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/index.html.twig', [
            'availableLocales' => ['en', 'ru', 'de'],
            'unavailableLocale' => null,
            'themePreference' => ThemePreference::System,
            'paginationMode' => PaginationMode::InfiniteScroll,
        ]);

        $this->assertStringContainsString('<option value="de">Deutsch</option>', $html);
        $this->assertStringNotContainsString('settings.locale.', $html);
    }

    public function testSearchIndexPageRendersReindexSuccessMessage(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/search_index/index.html.twig', ['reindexStatus' => 'success']);

        $this->assertStringContainsString('Поисковый индекс успешно перестроен.', $html);
    }

    public function testSearchIndexPageRendersReindexErrorMessage(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/search_index/index.html.twig', ['reindexStatus' => 'error']);

        $this->assertStringContainsString('Не удалось перестроить поисковый индекс.', $html);
    }

    /**
     * Regression (issue #817): rebuilding the search index is an infrequent maintenance action, so
     * its button must not carry the accented btn-primary styling reserved for a page's main action.
     */
    public function testSearchIndexPageRendersReindexButtonAsOutlineSecondaryAction(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('en');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/search_index/index.html.twig', ['reindexStatus' => null]);

        $matched = preg_match('/<button type="submit" class="([^"]*)">Rebuild search index<\/button>/', $html, $matches);
        self::assertSame(1, $matched, 'Expected the reindex button to be present.');
        self::assertSame('btn btn-outline-secondary', $matches[1]);
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

    /**
     * Acceptance (issue #382): a NeedsCorrection item renders one radio candidate per
     * participant, marks the engine's own best-effort winner, and posts the pick via HTMX to the
     * shared resolve route.
     */
    public function testSyncReviewIndexRendersNeedsCorrectionCandidatesWithAutoAppliedWinnerMarked(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();
        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        $item = new SyncReviewItem(SyncReviewItemKind::NeedsCorrection, [
            'anime_id' => 1,
            'winner_status' => 'completed',
            'winner_watched_episodes' => 12,
            'candidates' => [
                ['participant_id' => 'local', 'status' => 'completed', 'watched_episodes' => 12, 'updated_at' => 1735689600],
                ['participant_id' => 'animedb-shikimori', 'status' => 'watching', 'watched_episodes' => 5, 'updated_at' => 1735776000],
            ],
        ]);
        (new \ReflectionProperty(SyncReviewItem::class, 'id'))->setValue($item, 3);

        $anime = new TvAnime();
        $anime->setTitle('Trigun');
        (new \ReflectionProperty(Anime::class, 'id'))->setValue($anime, 1);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/sync_review/index.html.twig', [
            'items' => [$item],
            'duplicateClusters' => [3 => []],
            'needsCorrectionDetails' => [3 => [
                'anime' => $anime,
                'candidates' => $item->payload['candidates'],
                'winnerStatus' => 'completed',
                'winnerWatchedEpisodes' => 12,
            ]],
        ]);

        $this->assertStringContainsString('Trigun', $html);
        $this->assertStringContainsString('hx-post="/settings/sync-review/3/resolve"', $html);
        $this->assertStringContainsString('value="local"', $html);
        $this->assertStringContainsString('value="animedb-shikimori"', $html);
        $this->assertStringContainsString('Просмотрено', $html);
        $this->assertStringContainsString('Смотрю', $html);
        $this->assertStringContainsString('разрешено автоматически', $html);
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
