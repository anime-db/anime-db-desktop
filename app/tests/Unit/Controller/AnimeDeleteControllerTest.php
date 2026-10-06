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

use AnimeDb\PluginContracts\Sync\SyncRemovalInterface;
use App\Controller\AnimeDeleteController;
use App\Controller\Settings\SyncReviewController;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Download;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\Enum\WatchStatus;
use App\Entity\SyncReviewItem;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Message\RemoveFromSourceMessage;
use App\Message\SyncSeedMessage;
use App\Repository\AnimeRepository;
use App\Repository\AnimeSyncStateRepository;
use App\Repository\DownloadRepository;
use App\Repository\PendingSyncPushRepository;
use App\Repository\SyncReviewItemRepository;
use App\Service\AnimeDeleteFlash;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SyncRegistry;
use App\Service\Sync\DeletedFromSourceDetector;
use App\Service\Sync\SourceRemovalPlanner;
use App\Service\Sync\SyncConvergenceService;
use App\Service\Sync\SyncReconciler;
use App\Service\Sync\SyncReviewService;
use App\Tests\Support\BuildsAnimeDeleteService;
use App\Tests\Support\TemporaryDirectories;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

/**
 * Both entry points of the deletion (issue #916): the card's POST anime_delete and the "requires
 * attention" page's delete action, over a real {@see \App\Service\AnimeDeleteService}.
 */
final class AnimeDeleteControllerTest extends TestCase
{
    use BuildsAnimeDeleteService;
    use TemporaryDirectories;

    private EntityManager $entityManager;
    private string $mediaDir;
    private Session $session;

    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 3).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $this->entityManager = new EntityManager($connection, $config);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->mediaDir = $this->createTemporaryDirectory('anime-delete-controller-');
        $this->session = new Session(new MockArraySessionStorage());
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectories();
    }

    private function request(bool $validToken = true, bool $removeFromSources = false): Request
    {
        $request = new Request([], ['_token' => $validToken ? 'ok' : 'bad'] + ($removeFromSources ? ['remove_from_sources' => '1'] : []));
        $request->setSession($this->session);

        return $request;
    }

    private function csrf(): CsrfTokenManagerInterface
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (\Symfony\Component\Security\Csrf\CsrfToken $token): bool => $token->getValue() === 'ok');

        return $csrf;
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(static fn (string $name, array $params = []): string => '/'.$name.($params === [] ? '' : '/'.implode(',', $params)));

        return $urlGenerator;
    }

    private function flash(): AnimeDeleteFlash
    {
        return new AnimeDeleteFlash(new Translator('en'), $this->urlGenerator());
    }

    private function persistAnime(): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    private function cardController(): AnimeDeleteController
    {
        return new AnimeDeleteController($this->newAnimeDeleteService($this->mediaDir), $this->flash(), $this->csrf(), $this->urlGenerator());
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function flashes(): array
    {
        return $this->session->getFlashBag()->all();
    }

    public function testDeletingFromTheCardRedirectsToTheCatalogWithAFlashMessage(): void
    {
        $anime = $this->persistAnime();

        $response = $this->cardController()->delete($anime, $this->request());

        $this->assertSame('/home_index', $response->headers->get('Location'));
        $this->assertSame(['success' => [['text' => 'anime_delete.flash_deleted', 'link_url' => null, 'link_label' => null]]], $this->flashes());
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM anime'));
    }

    public function testAnInvalidCsrfTokenDeletesNothing(): void
    {
        $anime = $this->persistAnime();

        try {
            $this->cardController()->delete($anime, $this->request(validToken: false));
            $this->fail('An invalid token must be rejected.');
        } catch (BadRequestHttpException) {
        }

        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM anime'));
    }

    public function testAPendingDownloadGoesBackToTheCardWithALinkToTheDownloads(): void
    {
        $anime = $this->persistAnime();
        $this->entityManager->persist(new Download(str_repeat('a', 40), $anime));
        $this->entityManager->flush();

        $response = $this->cardController()->delete($anime, $this->request());

        $this->assertSame('/anime_show/'.$anime->id, $response->headers->get('Location'));
        $this->assertSame(['danger' => [['text' => 'anime_delete.flash_pending_downloads', 'link_url' => '/downloads_index', 'link_label' => 'anime_delete.downloads_link']]], $this->flashes());
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM anime'));
    }

    public function testASyncRunningRefusesFromTheCard(): void
    {
        $anime = $this->persistAnime();
        $jobLock = $this->newJobLockService();
        $jobLock->acquire(SyncSeedMessage::jobKey('animedb-shikimori'));
        $controller = new AnimeDeleteController(
            $this->newAnimeDeleteService($this->mediaDir, $this->newSyncRegistryWithActive(['animedb-shikimori']), $jobLock),
            $this->flash(),
            $this->csrf(),
            $this->urlGenerator(),
        );

        $controller->delete($anime, $this->request());

        $this->assertSame(['danger' => [['text' => 'anime_delete.flash_sync_running', 'link_url' => null, 'link_label' => null]]], $this->flashes());
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM anime'));
    }

    private function reviewController(?SyncRegistry $syncRegistry = null, ?\App\Service\JobLock\JobLockService $jobLock = null, ?MessageBusInterface $bus = null): SyncReviewController
    {
        $syncReviewItems = new SyncReviewItemRepository($this->entityManager);
        $syncReview = new SyncReviewService($syncReviewItems);
        $registry = new SyncRegistry([], new PluginsConfigStore(''));
        $states = new AnimeSyncStateRepository($this->entityManager);

        return new SyncReviewController(
            $syncReview,
            new AnimeRepository($this->entityManager),
            new SyncConvergenceService(new SyncReconciler(), $states, new PendingSyncPushRepository($this->entityManager), $registry, $syncReview, new NullLogger()),
            new DeletedFromSourceDetector($registry, $syncReview, $states),
            $this->newAnimeDeleteService($this->mediaDir, $syncRegistry, $jobLock, bus: $bus),
            $this->flash(),
            new SourceRemovalPlanner($syncRegistry ?? $registry),
            new DownloadRepository($this->entityManager),
            $this->entityManager,
            $this->csrf(),
            $this->urlGenerator(),
            $this->createStub(Environment::class),
        );
    }

    private function persistReviewItem(SyncReviewItemKind $kind, Anime $anime): SyncReviewItem
    {
        $item = new SyncReviewItem($kind, ['anime_id' => $anime->id, 'deleted_from' => 'animedb-shikimori']);
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return $item;
    }

    private function recordingBus(): MessageBusInterface
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        return $bus;
    }

    public function testDeletingFromRequiresAttentionOmitsTheSourceItWasDeletedFromAndAPluginSwitchedOffSinceTheDetection(): void
    {
        $registry = $this->newSyncRegistryOf([
            'animedb-shikimori' => $this->createStub(SyncRemovalInterface::class),
            'animedb-mal' => $this->createStub(SyncRemovalInterface::class),
            'acme-off' => $this->createStub(SyncRemovalInterface::class),
        ], ['animedb-shikimori', 'animedb-mal']);
        $anime = $this->persistAnime();
        $anime->rememberExternalId(new PluginId('animedb-shikimori'), '7');
        $anime->rememberExternalId(new PluginId('animedb-mal'), '8');
        $anime->rememberExternalId(new PluginId('acme-off'), '9');
        $item = $this->persistReviewItem(SyncReviewItemKind::DeletionConflict, $anime);
        $controller = $this->reviewController($registry, bus: $this->recordingBus());

        $controller->deleteAnime($item, $this->request(removeFromSources: true));

        $this->assertEquals([new RemoveFromSourceMessage('animedb-mal', '8')], $this->dispatched);
        $flags = $this->entityManager->getConnection()->fetchAllKeyValue('SELECT plugin_id, removal_pending FROM sync_tombstone');
        $this->assertEquals(['acme-off' => 0, 'animedb-mal' => 1, 'animedb-shikimori' => 0], $flags);
    }

    public function testDeletingFromRequiresAttentionWithoutTheCheckboxQueuesNothing(): void
    {
        $registry = $this->newSyncRegistryOf(['animedb-mal' => $this->createStub(SyncRemovalInterface::class)], ['animedb-mal']);
        $anime = $this->persistAnime();
        $anime->rememberExternalId(new PluginId('animedb-mal'), '8');
        $item = $this->persistReviewItem(SyncReviewItemKind::DeletionConflict, $anime);

        $this->reviewController($registry, bus: $this->recordingBus())->deleteAnime($item, $this->request());

        $this->assertSame([], $this->dispatched);
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM sync_tombstone WHERE removal_pending = 1'));
    }

    public function testTheCardPassesTheCheckboxToTheService(): void
    {
        $registry = $this->newSyncRegistryOf(['animedb-mal' => $this->createStub(SyncRemovalInterface::class)], ['animedb-mal']);
        $anime = $this->persistAnime();
        $anime->rememberExternalId(new PluginId('animedb-mal'), '8');
        $controller = new AnimeDeleteController($this->newAnimeDeleteService($this->mediaDir, $registry, bus: $this->recordingBus()), $this->flash(), $this->csrf(), $this->urlGenerator());

        $controller->delete($anime, $this->request(removeFromSources: true));

        $this->assertEquals([new RemoveFromSourceMessage('animedb-mal', '8')], $this->dispatched);
    }

    public function testDeletingFromRequiresAttentionDeletesTheEntryAndClosesTheItem(): void
    {
        $anime = $this->persistAnime();
        $anime->rememberExternalId(new PluginId('animedb-shikimori'), '7');
        $download = new Download(str_repeat('c', 40), $anime);
        $download->markCompleted();
        $this->entityManager->persist($download);
        $item = $this->persistReviewItem(SyncReviewItemKind::DeletionConflict, $anime);
        $itemId = $item->id;

        $response = $this->reviewController()->deleteAnime($item, $this->request());

        $this->assertSame('/settings_sync_review_index', $response->headers->get('Location'));
        $this->entityManager->clear();
        $this->assertTrue($this->entityManager->find(SyncReviewItem::class, $itemId)?->isResolved());
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM anime'));
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM sync_tombstone WHERE plugin_id = 'animedb-shikimori' AND external_id = '7'"));
        $this->assertArrayHasKey('success', $this->flashes());
    }

    public function testARefusedDeletionFromRequiresAttentionLeavesTheItemOpen(): void
    {
        $anime = $this->persistAnime();
        $this->entityManager->persist(new Download(str_repeat('b', 40), $anime));
        $item = $this->persistReviewItem(SyncReviewItemKind::DeletedFromSource, $anime);

        $this->reviewController()->deleteAnime($item, $this->request());

        $this->assertFalse($item->isResolved());
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM anime'));
        $this->assertArrayHasKey('danger', $this->flashes());
    }

    public function testDeletingAnEntryThatIsAlreadyGoneJustClosesTheItem(): void
    {
        $anime = $this->persistAnime();
        $item = $this->persistReviewItem(SyncReviewItemKind::DeletedFromSource, $anime);
        $this->entityManager->remove($anime);
        $this->entityManager->flush();

        $this->reviewController()->deleteAnime($item, $this->request());

        $this->assertTrue($item->isResolved());
    }

    public function testOtherItemKindsCannotDeleteAnEntry(): void
    {
        $anime = $this->persistAnime();
        $item = $this->persistReviewItem(SyncReviewItemKind::NeedsCorrection, $anime);

        $this->expectException(BadRequestHttpException::class);

        try {
            $this->reviewController()->deleteAnime($item, $this->request());
        } finally {
            $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM anime'));
        }
    }
}
