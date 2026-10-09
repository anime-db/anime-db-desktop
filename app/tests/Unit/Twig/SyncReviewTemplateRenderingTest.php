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
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Sync\SourceRemovalPlan;
use App\Twig\PluginNameExtension;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

final class SyncReviewTemplateRenderingTest extends KernelTestCase
{
    private string $pluginsDir;

    protected function setUp(): void
    {
        $this->pluginsDir = sys_get_temp_dir().'/anime-sync-review-plugins-'.uniqid();
        mkdir($this->pluginsDir.'/animedb-shikimori', recursive: true);
        file_put_contents($this->pluginsDir.'/animedb-shikimori/manifest.json', (string) json_encode([
            'id' => 'animedb-shikimori',
            'name' => 'Shikimori Sync',
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['widget' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->remove($this->pluginsDir);
    }

    /** @param array<int, array<string, mixed>> $needsCorrection */
    private function render(SyncReviewItemKind $kind, bool $withAnime = true, ?SourceRemovalPlan $plan = null, array $needsCorrection = []): string
    {
        self::bootKernel();
        $registry = new InstalledPluginsRegistry($this->pluginsDir, new PluginsConfigStore($this->pluginsDir.'/plugins.json'), new NullLogger());
        $registry->reconcile();
        self::getContainer()->set(PluginNameExtension::class, new PluginNameExtension($registry));

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
            'needsCorrectionDetails' => $needsCorrection,
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
        $this->assertStringContainsString('the plugin cannot delete list entries', $html);
        $this->assertStringContainsString('sync is off for it', $html);
        $this->assertStringNotContainsString('anime_delete.kept_', $html);
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

    public function testAnInstalledSourceIsShownByItsManifestNameAndAnUninstalledOneByItsId(): void
    {
        $html = $this->render(SyncReviewItemKind::DeletionConflict);
        $this->assertStringContainsString('Shikimori Sync', $html);
        $this->assertStringNotContainsString('animedb-shikimori', $html);
        $this->assertStringContainsString('animedb-mal', $html);

        $this->assertStringContainsString('Shikimori Sync', $this->render(SyncReviewItemKind::DeletedFromSource));
    }

    public function testCandidatesAreLabelledWithThePluginNameOrLocal(): void
    {
        $candidates = [
            ['participant_id' => 'animedb-shikimori', 'status' => 'watching', 'watched_episodes' => 3, 'updated_at' => null],
            ['participant_id' => 'animedb-mal', 'status' => 'completed', 'watched_episodes' => 5, 'updated_at' => null],
            ['participant_id' => 'local', 'status' => 'planned', 'watched_episodes' => null, 'updated_at' => null],
        ];
        $html = $this->render(SyncReviewItemKind::NeedsCorrection, needsCorrection: [20 => ['anime' => null, 'candidates' => $candidates, 'winnerStatus' => null, 'winnerWatchedEpisodes' => null]]);

        $this->assertStringContainsString('<bdi>Shikimori Sync</bdi>', $html);
        $this->assertStringContainsString('<bdi>animedb-mal</bdi>', $html);
        $this->assertStringContainsString('<bdi>Local</bdi>', $html);
        $this->assertStringContainsString('value="animedb-shikimori"', $html);
    }

    public function testResolveButtonSaysWhatItDoesForEachKind(): void
    {
        foreach ([SyncReviewItemKind::DeletedFromSource, SyncReviewItemKind::DeletionConflict] as $kind) {
            $html = $this->render($kind);
            $this->assertStringContainsString('Keep in catalog', $html);
            $this->assertStringNotContainsString('Not a duplicate', $html);
        }

        $html = $this->render(SyncReviewItemKind::PotentialDuplicate);
        $this->assertStringContainsString('Not a duplicate', $html);
        $this->assertStringNotContainsString('Keep in catalog', $html);
    }
}
