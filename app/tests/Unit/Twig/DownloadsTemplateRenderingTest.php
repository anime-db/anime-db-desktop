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
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Environment;

/**
 * Pins downloads/index.html.twig's own markup (issue #854) — separately from
 * DownloadsControllerTest/DownloadsStatusControllerTest, which assert on the view-model the
 * controllers build but stub out Twig::render() itself (same split as every other page in this
 * suite, e.g. SyncReviewController vs. this file's sibling sync_review coverage above).
 */
final class DownloadsTemplateRenderingTest extends KernelTestCase
{
    private function pushRequestWithSession(): void
    {
        $request = Request::create('/downloads');
        $request->setSession(new Session(new MockArraySessionStorage()));

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function render(array $params): string
    {
        self::bootKernel();
        $this->pushRequestWithSession();
        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return $twig->render('downloads/index.html.twig', $params);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'infoHash' => str_repeat('a', 40),
            'hasCard' => true,
            'animeUrl' => '/anime/1',
            'displayName' => 'Trigun',
            'statusText' => 'Ждёт',
            'sizeText' => '700.0 MB',
            'progressText' => '40%',
            'downloadSpeedText' => '50.0 KB/s',
            'uploadSpeedText' => '0 B/s',
            'etaText' => '2:00',
            'peersText' => '3/1',
            'targetStorageName' => 'Main folder',
            'id' => 1,
            'coreStatus' => 'pending',
            'version' => 1,
            'canPause' => false,
            'canResume' => false,
            'canRetry' => false,
            'canStopSeeding' => false,
            'canDelete' => false,
            'hasTorrentInClient' => false,
            'deleteFilesDefaultChecked' => false,
            'canDeleteFiles' => false,
        ], $overrides);
    }

    /**
     * The table is always rendered (just hidden) rather than omitted: downloads-list.js needs a
     * live <tbody data-downloads-rows> to append rows into whenever a later poll response turns
     * out non-empty, which an SSR-only "no table at all" markup could never provide without a
     * full page reload.
     */
    public function testEmptyStateRendersAHiddenTableAndAVisibleEmptyMessage(): void
    {
        $html = $this->render(['rows' => [], 'orphans' => [], 'qbittorrentAvailable' => true]);

        $this->assertStringContainsString('Загрузок пока нет.', $html);
        $this->assertMatchesRegularExpression('/data-downloads-table-wrapper\b[^>]*\bhidden\b/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-downloads-empty\b[^>]*\bhidden\b/', $html);
    }

    public function testNonEmptyStateRendersAVisibleTableAndAHiddenEmptyMessage(): void
    {
        $html = $this->render(['rows' => [$this->row()], 'orphans' => [], 'qbittorrentAvailable' => true]);

        $this->assertDoesNotMatchRegularExpression('/data-downloads-table-wrapper\b[^>]*\bhidden\b/', $html);
        $this->assertMatchesRegularExpression('/data-downloads-empty\b[^>]*\bhidden\b/', $html);
    }

    public function testRowRendersAsALinkToTheAnimeCardWithoutTheNoCardBadge(): void
    {
        $html = $this->render(['rows' => [$this->row()], 'orphans' => [], 'qbittorrentAvailable' => true]);

        $this->assertStringContainsString('<a href="/anime/1">', $html);
        $this->assertStringContainsString('Trigun', $html);
        // The translated label also sits in the root's data-no-card-label attribute (read by
        // downloads-list.js), so the absence check below targets the visible badge markup, not
        // the bare translated string anywhere on the page.
        $this->assertStringNotContainsString('badge text-bg-secondary', $html);
    }

    public function testOrphanRowRendersWithoutALinkAndWithTheNoCardBadge(): void
    {
        $orphan = $this->row(['hasCard' => false, 'animeUrl' => null, 'displayName' => 'Mystery torrent']);
        $html = $this->render(['rows' => [], 'orphans' => [$orphan], 'qbittorrentAvailable' => true]);

        $this->assertStringContainsString('Mystery torrent', $html);
        $this->assertStringContainsString('Без карточки', $html);
        $this->assertStringNotContainsString('<a href="/anime/1">', $html);
    }

    public function testBannerIsHiddenWhenQbittorrentIsAvailable(): void
    {
        $html = $this->render(['rows' => [$this->row()], 'orphans' => [], 'qbittorrentAvailable' => true]);

        $this->assertMatchesRegularExpression('/data-downloads-banner\b[^>]*\bhidden\b/', $html);
    }

    public function testBannerIsVisibleAndTextedWhenQbittorrentIsUnavailable(): void
    {
        $html = $this->render(['rows' => [$this->row()], 'orphans' => [], 'qbittorrentAvailable' => false]);

        $this->assertDoesNotMatchRegularExpression('/data-downloads-banner\b[^>]*\bhidden\b/', $html);
        $this->assertStringContainsString('Не удалось получить данные от торрент-клиента.', $html);
    }

    /**
     * Issue #856: a row's action buttons are gated on the server-computed can* flags, not shown
     * unconditionally — storage_conflict/legacy_layout rows (canRetry false) must never render a
     * "Retry" button the backend would refuse anyway.
     */
    public function testRowRendersOnlyTheActionsItIsEligibleFor(): void
    {
        $row = $this->row(['canPause' => true, 'canRetry' => false, 'canDelete' => true, 'hasTorrentInClient' => true, 'canDeleteFiles' => true]);
        $html = $this->render(['rows' => [$row], 'orphans' => [], 'qbittorrentAvailable' => true]);

        $this->assertStringContainsString('Пауза', $html);
        $this->assertStringNotContainsString('>Повторить<', $html);
        $this->assertStringContainsString('>Удалить<', $html);
        $this->assertStringContainsString('name="delete_files"', $html);
    }

    public function testDeleteFormOmitsTheFilesCheckboxWhenDataIsNotInIncoming(): void
    {
        $row = $this->row(['canDelete' => true, 'hasTorrentInClient' => true, 'canDeleteFiles' => false]);
        $html = $this->render(['rows' => [$row], 'orphans' => [], 'qbittorrentAvailable' => true]);

        $this->assertStringContainsString('>Удалить<', $html);
        $this->assertStringNotContainsString('name="delete_files"', $html);
    }

    public function testOrphanDeleteFormHasTheFilesCheckboxOnlyWhenDataIsInIncoming(): void
    {
        $withFiles = $this->row(['hasCard' => false, 'animeUrl' => null, 'canDeleteFiles' => true]);
        $without = $this->row(['hasCard' => false, 'animeUrl' => null, 'canDeleteFiles' => false]);

        $this->assertStringContainsString('name="delete_files"', $this->render(['rows' => [], 'orphans' => [$withFiles], 'qbittorrentAvailable' => true]));
        $this->assertStringNotContainsString('name="delete_files"', $this->render(['rows' => [], 'orphans' => [$without], 'qbittorrentAvailable' => true]));
    }

    public function testDeleteFormOmitsTheFilesCheckboxWhenThereIsNoTorrentInTheClient(): void
    {
        $row = $this->row(['canDelete' => true, 'hasTorrentInClient' => false]);
        $html = $this->render(['rows' => [$row], 'orphans' => [], 'qbittorrentAvailable' => true]);

        $this->assertStringContainsString('>Удалить<', $html);
        $this->assertStringNotContainsString('name="delete_files"', $html);
    }

    public function testOrphanRowRendersADeleteFromClientButton(): void
    {
        $orphan = $this->row(['hasCard' => false, 'animeUrl' => null, 'displayName' => 'Mystery torrent']);
        $html = $this->render(['rows' => [], 'orphans' => [$orphan], 'qbittorrentAvailable' => true]);

        $this->assertStringContainsString('Удалить из клиента', $html);
    }

    public function testActionErrorRendersAsADangerAlert(): void
    {
        $html = $this->render([
            'rows' => [],
            'orphans' => [],
            'qbittorrentAvailable' => true,
            'actionError' => 'downloads.action_error_conflict',
        ]);

        $this->assertStringContainsString('alert-danger', $html);
        $this->assertStringContainsString('Состояние загрузки изменилось', $html);
    }
}
