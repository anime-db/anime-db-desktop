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

use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\SyncReviewItem;
use App\Entity\TvAnime;
use App\Service\Sync\SourceRemovalPlan;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

final class SyncReviewTemplateRenderingTest extends KernelTestCase
{
    private function render(SyncReviewItemKind $kind, bool $withAnime = true, ?SourceRemovalPlan $plan = null): string
    {
        self::bootKernel();

        $request = Request::create('/settings/sync-review');
        $request->setSession(new Session(new MockArraySessionStorage()));
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);

        $item = new SyncReviewItem($kind, ['anime_id' => 7, 'deleted_from' => 'animedb-shikimori', 'still_present_on' => ['animedb-mal']]);
        (new \ReflectionProperty(SyncReviewItem::class, 'id'))->setValue($item, 20);
        $anime = new TvAnime();
        $anime->setTitle('Trigun');
        (new \ReflectionProperty(\App\Entity\Anime::class, 'id'))->setValue($anime, 7);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return $twig->render('settings/sync_review/index.html.twig', [
            'items' => [$item],
            'duplicateClusters' => [20 => []],
            'deletionDetails' => [20 => ['anime' => $withAnime ? $anime : null, 'deletedFrom' => 'animedb-shikimori', 'stillPresentOn' => ['animedb-mal'], 'hasStorage' => true, 'hasFinishedDownloads' => true, 'sourceRemoval' => $plan ?? new SourceRemovalPlan()]],
            'needsCorrectionDetails' => [],
        ]);
    }

    public function testBothDeletionKindsOfferDeletingTheRecordNextToResolve(): void
    {
        foreach ([SyncReviewItemKind::DeletedFromSource, SyncReviewItemKind::DeletionConflict] as $kind) {
            $html = $this->render($kind);

            $this->assertStringContainsString('action="/settings/sync-review/20/resolve"', $html);
            $this->assertMatchesRegularExpression('#action="/settings/sync-review/20/delete-anime"\s+data-confirm="[^"]*Trigun[^"]*video files[^"]*torrents[^"]*"#', $html);
            $this->assertStringContainsString('Delete entry', $html);
        }
    }

    public function testTheDialogOffersRemovalOnTheSourcesCheckedByDefaultAndNamesThem(): void
    {
        $plan = new SourceRemovalPlan(
            ['acme-list' => '1'],
            ['Acme List'],
            [['name' => 'Other List', 'reason' => SourceRemovalPlan::REASON_NO_REMOVAL], ['name' => 'Off List', 'reason' => SourceRemovalPlan::REASON_INACTIVE]],
        );

        $html = $this->render(SyncReviewItemKind::DeletionConflict, plan: $plan);

        $this->assertMatchesRegularExpression('#<input[^>]*type="checkbox"[^>]*name="remove_from_sources"[^>]*checked#', $html);
        $this->assertStringContainsString('Also delete from the lists on the sources', $html);
        $this->assertStringContainsString('It will be deleted from the list on: ', $html);
        $this->assertStringContainsString('Acme List', $html);
        $this->assertStringContainsString('It stays on: ', $html);
        $this->assertStringContainsString('Other List', $html);
        $this->assertStringContainsString('Off List', $html);
        $this->assertStringNotContainsString('data-confirm', $html);
    }

    public function testNoCheckboxWithoutTargets(): void
    {
        $this->assertStringNotContainsString('remove_from_sources', $this->render(SyncReviewItemKind::DeletionConflict));
    }

    public function testNoDeleteActionWhenTheEntryIsAlreadyGone(): void
    {
        $this->assertStringNotContainsString('delete-anime', $this->render(SyncReviewItemKind::DeletedFromSource, withAnime: false));
    }
}
