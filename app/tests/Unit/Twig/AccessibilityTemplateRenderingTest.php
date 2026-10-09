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
use App\Service\Storage\Scan\ScanRun;
use App\Service\Storage\Scan\ScanRunStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * Real-Twig render coverage for the accessibility attributes of issue #1011: names for fields that
 * had none, live regions, associated error messages and fieldset/legend groups.
 */
final class AccessibilityTemplateRenderingTest extends KernelTestCase
{
    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context): string
    {
        self::bootKernel();

        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return $twig->render($template, $context);
    }

    private function storage(): Storage
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($storage, 7);

        return $storage;
    }

    public function testDownloadNewAndAdoptSearchFieldIsACombobox(): void
    {
        $new = $this->render('downloads/new.html.twig', [
            'selectedAnime' => null, 'magnet' => '', 'storages' => [$this->storage()], 'presetFailed' => false,
            'selectedStorageId' => 7, 'error' => null, 'errorParams' => [], 'info' => null, 'occupyingAnimeId' => null,
        ]);
        $adopt = $this->render('downloads/adopt.html.twig', [
            'infoHash' => 'a', 'form' => ['action' => '/x', 'tokenId' => 't', 'hidden' => []], 'torrentName' => 'n',
            'selectedAnime' => null, 'error' => null, 'errorParams' => [], 'blocked' => false, 'backUrl' => '/downloads',
        ]);

        foreach ([$new, $adopt] as $html) {
            $this->assertMatchesRegularExpression('/<input[^>]*id="download-new-anime-search"[^>]*role="combobox"[^>]*aria-expanded="false"[^>]*aria-controls="download-new-anime-results"/', $html);
            $this->assertMatchesRegularExpression('/<ul[^>]*id="download-new-anime-results"[^>]*role="listbox"/', $html);
        }
    }

    public function testCatalogListAnnouncesResultsAndExposesSortAndPaginationState(): void
    {
        $html = $this->render('anime/list.html.twig', [
            'showOnboarding' => false, 'hasScannableStorage' => false, 'singleScannableStorageId' => null, 'widgets' => [],
            'collapsedFilterSections' => [], 'hasActiveFillerPlugin' => false,
            'noFillerState' => ['kind' => 'not_installed', 'url' => '/settings/market'],
        ]);

        $this->assertMatchesRegularExpression('/id="anime-list-empty"[^>]*role="status"/', $html);
        $this->assertMatchesRegularExpression('/id="anime-list-error"[^>]*role="alert"/', $html);
        $this->assertMatchesRegularExpression('/id="anime-list-chips-shown"[^>]*role="status"/', $html);
        $this->assertMatchesRegularExpression('/<nav[^>]*id="anime-list-pagination"[^>]*aria-label="Catalog pages"/', $html);
        $this->assertStringContainsString('data-sort-field="date_update"', $html);
        $this->assertMatchesRegularExpression('/data-sort-field="date_update"\s+aria-pressed="true"/', $html);
        $this->assertMatchesRegularExpression('/data-sort-field="name"\s+aria-pressed="false"/', $html);
        $this->assertStringNotContainsString('aria-current', $html);
    }

    public function testWidgetSettingsControlsAreNamedAfterTheWidget(): void
    {
        $row = [[
            'pluginId' => 'p', 'widgetName' => 'related', 'active' => true, 'slot' => 'bottom',
            'title' => 'Related anime', 'description' => '', 'pluginName' => 'Shikimori',
        ]];
        $html = $this->render('settings/plugin/widgets.html.twig', [
            'entryWidgets' => $row, 'catalogWidgets' => [], 'entryActiveCount' => 1, 'catalogActiveCount' => 0,
            'entryHardLimit' => 5, 'catalogHardLimit' => 2, 'recommendedLimit' => 3, 'error' => null, 'limitReached' => 0,
        ]);

        $this->assertStringContainsString('aria-label="Active: Related anime"', $html);
        $this->assertStringContainsString('aria-label="Position: Related anime"', $html);
    }

    public function testLabelSettingsFieldsHaveNames(): void
    {
        $html = $this->render('settings/label/index.html.twig', [
            'labels' => [['id' => 1, 'name' => 'Favourite']], 'labelCounts' => [1 => 0], 'error' => null,
        ]);

        $this->assertStringContainsString('aria-label="New tag"', $html);
        $this->assertStringContainsString('aria-label="Tag name"', $html);
    }

    public function testInlineEditFieldsHaveNames(): void
    {
        $anime = [
            'id' => 5, 'is_series' => true, 'episodes_count' => 12, 'watched_episodes' => 3, 'watch_status' => 'watching',
            'production_status' => 'released', 'user_rating' => null, 'notes' => '',
        ];

        $progress = $this->render('anime/_editable.html.twig', ['anime' => $anime, 'editing' => 'watched_episodes', 'error' => null, 'watch_statuses' => ['watching']]);
        $this->assertMatchesRegularExpression('/name="watched_episodes"\s+aria-label="Episodes watched"/', $progress);

        $status = $this->render('anime/_editable.html.twig', ['anime' => $anime, 'editing' => 'watch_status', 'error' => null, 'watch_statuses' => ['watching']]);
        $this->assertStringContainsString('<select name="watch_status" aria-label="Watch status">', $status);
    }

    /**
     * @param array<string, string> $errors
     */
    private function renderEdit(array $errors): string
    {
        return $this->render('anime/edit.html.twig', [
            'anime' => ['id' => 5, 'title' => 'T', 'type' => 'tv'],
            'is_series' => true,
            'form' => [
                'title' => '', 'names' => [['name' => 'a', 'locale' => 'en', 'role' => 'synonym']], 'descriptions' => [],
                'genres' => [], 'themes' => [], 'studios' => [], 'new_studios' => [], 'demographic' => '',
                'date_premiere' => '', 'date_end' => '', 'duration_minutes' => '', 'episodes_count' => '', 'countries' => '',
                'sources' => [], 'cover_remove' => false, 'notes' => '',
            ],
            'errors' => $errors,
            'genre_choices' => ['action'], 'theme_choices' => ['mecha'], 'demographic_choices' => [], 'role_choices' => ['synonym'],
            'studio_choices' => [], 'cover' => null, 'cover_max_bytes' => 1048576, 'csrf_token_id' => 'anime_edit_5',
        ]);
    }

    public function testEditFormLinksAFieldToItsErrorMessage(): void
    {
        $html = $this->renderEdit(['title' => 'anime_edit.error_title_required', 'genres' => 'anime_edit.error_title_required', 'names.0' => 'anime_edit.error_title_required']);

        $this->assertMatchesRegularExpression('/id="anime-edit-title"[^>]*class="form-control is-invalid"[^>]*aria-describedby="anime-edit-error-title"[^>]*aria-invalid="true"/', $html);
        $this->assertStringContainsString('id="anime-edit-error-title"', $html);
        $this->assertMatchesRegularExpression('/name="names\[0\]\[name\]"[^>]*aria-describedby="anime-edit-error-names-0"/', $html);
        $this->assertStringContainsString('id="anime-edit-error-names-0"', $html);
        $this->assertMatchesRegularExpression('/<fieldset[^>]*aria-describedby="anime-edit-error-genres"[^>]*aria-invalid="true"/', $html);
    }

    public function testEditFormWithoutErrorsHasNoErrorAssociations(): void
    {
        $html = $this->renderEdit([]);

        $this->assertStringNotContainsString('aria-invalid', $html);
        $this->assertStringNotContainsString('aria-describedby', $html);
        $this->assertStringNotContainsString('is-invalid', $html);
    }

    public function testEditFormGroupCaptionsAreLegends(): void
    {
        $html = $this->renderEdit([]);

        foreach (['Alternative titles', 'Descriptions', 'Genres', 'Themes', 'Studios', 'Source links'] as $text) {
            $this->assertMatchesRegularExpression('/<fieldset[^>]*>\s*<legend class="form-label">'.$text.'<\/legend>/', $html);
        }
        $this->assertSame(6, substr_count($html, '<fieldset'));
        $this->assertSame(6, substr_count($html, '</fieldset>'));
        $this->assertStringNotContainsString('<label class="form-label">', $html);
    }

    public function testScanProgressAreaIsALiveRegionWithANamedBarAndAlert(): void
    {
        $html = $this->render('storage/scan_progress.html.twig', ['storage' => $this->storage(), 'started' => true, 'latestRun' => null, 'failedRun' => null]);

        $this->assertStringContainsString('id="storage-scan-progress" aria-live="polite"', $html);
        $this->assertMatchesRegularExpression('/role="progressbar"[^>]*aria-label="Scan progress"/', $html);
        $this->assertStringContainsString('<p id="storage-scan-error" role="alert" hidden>', $html);
    }

    public function testScanRunResultErrorsAreAlerts(): void
    {
        $failed = new ScanRun(3, 7, new \DateTimeImmutable('@1000'), new \DateTimeImmutable('@1060'), ScanRunStatus::Failed, 'boom', [], []);
        $html = $this->render('storage/_scan_run_results.html.twig', ['storage' => $this->storage(), 'run' => $failed]);
        $this->assertMatchesRegularExpression('/<p class="alert alert-danger" role="alert">/', $html);

        $done = new ScanRun(3, 7, new \DateTimeImmutable('@1000'), new \DateTimeImmutable('@1060'), ScanRunStatus::Done, null, [], []);
        $html = $this->render('storage/_scan_run_results.html.twig', ['storage' => $this->storage(), 'run' => $done]);
        $this->assertStringContainsString('<p id="storage-scan-error" role="alert" hidden>', $html);
    }
}
