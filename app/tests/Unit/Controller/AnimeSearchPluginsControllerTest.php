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

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use App\Controller\AnimeSearchPluginsController;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Repository\AnimeRepository;
use App\Repository\StudioRepository;
use App\Service\Plugin\Filler\BulkFillerService;
use App\Service\Plugin\Filler\CachedFillerLookup;
use App\Service\Plugin\Filler\PluginAnimeDataMerger;
use App\Service\Plugin\Filler\PluginMediaDownloaderInterface;
use App\Service\Plugin\FillerAvailabilityPresenter;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SettingsPageRegistry;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

/**
 * Exercises the "search in plugins" screen's controller (issue #833) against a real
 * FillerRegistry/InstalledPluginsRegistry/BulkFillerService/CachedFillerLookup/AnimeRepository
 * stack, backed by an in-memory SQLite EntityManager — the same direct-instantiation style
 * BulkFillerServiceTest and AnimeFillControllerTest already use (this codebase has no
 * WebTestCase-based HTTP client test anywhere). Only Twig::render() is mocked, so assertions
 * read the context array handed to it rather than parsing rendered HTML.
 */
final class AnimeSearchPluginsControllerTest extends TestCase
{
    private EntityManager $entityManager;
    private AnimeRepository $animeRepository;
    private string $pluginsDir;
    private string $pluginsConfigPath;

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
        $this->entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->animeRepository = new AnimeRepository($this->entityManager);

        $this->pluginsDir = sys_get_temp_dir().'/anime-search-plugins-controller-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);
        $this->pluginsConfigPath = $this->pluginsDir.'/plugins.json';
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginsDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeManifest(string $pluginId, string $name): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => $name,
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
    }

    /** @param iterable<string, FillerInterface> $fillers */
    private function createController(
        iterable $fillers,
        ?Environment $twig = null,
        ?UrlGeneratorInterface $urlGenerator = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ): AnimeSearchPluginsController {
        $pluginsConfigStore = new PluginsConfigStore($this->pluginsConfigPath);
        $fillerRegistry = new FillerRegistry($fillers, $pluginsConfigStore);
        $installedPlugins = new InstalledPluginsRegistry($this->pluginsDir, $pluginsConfigStore, new NullLogger());
        $installedPlugins->reconcile();
        $settingsPages = new SettingsPageRegistry($installedPlugins, new ServiceLocator([]));

        $urlGenerator ??= $this->stubUrlGenerator();

        $lookup = new CachedFillerLookup(new ArrayAdapter());

        $bulkFiller = new BulkFillerService(
            $fillerRegistry,
            new PluginAnimeDataMerger(
                new StudioRepository($this->entityManager),
                $this->entityManager,
                $this->createStub(PluginMediaDownloaderInterface::class),
            ),
            $this->entityManager,
            new NullLogger(),
            $this->createStub(\Symfony\Component\Messenger\MessageBusInterface::class),
            $this->animeRepository,
            $lookup,
        );

        if ($csrfTokenManager === null) {
            $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
            $csrfTokenManager->method('isTokenValid')->willReturn(true);
        }

        return new AnimeSearchPluginsController(
            $fillerRegistry,
            $installedPlugins,
            new FillerAvailabilityPresenter($fillerRegistry, $installedPlugins, $settingsPages, $urlGenerator),
            $lookup,
            $this->animeRepository,
            $bulkFiller,
            $this->entityManager,
            $this->createStub(EventDispatcherInterface::class),
            $csrfTokenManager,
            $urlGenerator,
            $twig ?? $this->createStub(Environment::class),
            new NullLogger(),
        );
    }

    private function stubUrlGenerator(): UrlGeneratorInterface
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $name, array $params = []): string => '/'.$name.(($params !== []) ? '?'.http_build_query($params) : ''),
        );

        return $urlGenerator;
    }

    public function testGroupRendersCandidatesWithPluginNameFromManifest(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('find')->willReturn([
            new SearchByPluginCandidate('animedb-shikimori', 'Trigun', '1'),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/search_plugins/_group.html.twig', $this->callback(static function (array $params): bool {
                self::assertSame('animedb-shikimori', $params['pluginId']);
                self::assertSame('Shikimori', $params['pluginName']);
                self::assertSame('results', $params['state']);
                self::assertSame([['name' => 'Trigun', 'externalId' => '1']], $params['candidates']);

                return true;
            }))
            ->willReturn('<section></section>');

        $controller = $this->createController(['animedb-shikimori' => $filler], $twig);
        $controller->group('animedb-shikimori', Request::create('/anime/search-plugins/results/animedb-shikimori?q=Trigun'));
    }

    public function testGroupRendersErrorStateWhenPluginFindThrows(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('find')->willThrowException(new \RuntimeException('timed out'));

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/search_plugins/_group.html.twig', $this->callback(
                static fn (array $params): bool => $params['state'] === 'error' && $params['candidates'] === [],
            ))
            ->willReturn('<section></section>');

        $controller = $this->createController(['animedb-shikimori' => $filler], $twig);
        $controller->group('animedb-shikimori', Request::create('/anime/search-plugins/results/animedb-shikimori?q=Trigun'));
    }

    public function testGroupRendersEmptyStateWhenPluginFindsNothing(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('find')->willReturn([]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/search_plugins/_group.html.twig', $this->callback(
                static fn (array $params): bool => $params['state'] === 'empty' && $params['candidates'] === [],
            ))
            ->willReturn('<section></section>');

        $controller = $this->createController(['animedb-shikimori' => $filler], $twig);
        $controller->group('animedb-shikimori', Request::create('/anime/search-plugins/results/animedb-shikimori?q=Trigun'));
    }

    public function testPreviewCallsFindByIdOnceAndReusesCacheOnSecondOpen(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        $data = new PluginAnimeData(title: 'Trigun');
        $filler = $this->createMock(FillerInterface::class);
        $filler->expects($this->once())->method('findById')->with('1')->willReturn($data);

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<div></div>');

        $controller = $this->createController(['animedb-shikimori' => $filler], $twig);

        $request = Request::create('/anime/search-plugins/preview?plugin_id=animedb-shikimori&external_id=1&name=Trigun');
        $controller->preview($request);
        $controller->preview($request);
    }

    public function testAddReusesThePreviewsCachedFindByIdAndRedirectsToTheCreatedAnime(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        $data = new PluginAnimeData(title: 'Trigun');
        $filler = $this->createMock(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['title']);
        $filler->expects($this->once())->method('findById')->with('1')->willReturn($data);

        $controller = $this->createController(['animedb-shikimori' => $filler]);

        // Opens the preview first, same as a user clicking the candidate before adding it.
        $controller->preview(Request::create('/anime/search-plugins/preview?plugin_id=animedb-shikimori&external_id=1&name=Trigun'));

        $addRequest = Request::create('/anime/search-plugins/add', 'POST', [
            'plugin_id' => 'animedb-shikimori',
            'external_id' => '1',
            'name' => 'Trigun',
            '_token' => 'irrelevant',
        ]);
        $response = $controller->add($addRequest);

        $this->assertStringStartsWith('/anime_show?', $response->headers->get('Location') ?? '');
        $this->assertNotNull($this->animeRepository->resolve(new \App\Entity\ValueObject\PluginId('animedb-shikimori'), '1'));
    }

    public function testAddingTheSamePluginAndExternalIdTwiceDoesNotDuplicateAndRedirectsToTheSameAnime(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['title']);
        $filler->method('findById')->willReturn(new PluginAnimeData(title: 'Trigun'));

        $controller = $this->createController(['animedb-shikimori' => $filler]);

        $addRequest = static fn (): Request => Request::create('/anime/search-plugins/add', 'POST', [
            'plugin_id' => 'animedb-shikimori',
            'external_id' => '1',
            'name' => 'Trigun',
            '_token' => 'irrelevant',
        ]);

        $first = $controller->add($addRequest());
        $second = $controller->add($addRequest());

        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        $this->assertSame(1, $this->entityManager->getRepository(\App\Entity\Anime::class)->count([]));
    }

    public function testPreviewShowsPossibleMatchForARecordWithoutThisPluginsExternalId(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        $existing = new TvAnime();
        $existing->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('findById')->willReturn(new PluginAnimeData(title: 'Trigun'));

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/search_plugins/_preview.html.twig', $this->callback(static function (array $params) use ($existing): bool {
                self::assertSame('possible_match', $params['state']);
                self::assertSame($existing, $params['possibleMatch']);

                return true;
            }))
            ->willReturn('<div></div>');

        $controller = $this->createController(['animedb-shikimori' => $filler], $twig);
        $controller->preview(Request::create('/anime/search-plugins/preview?plugin_id=animedb-shikimori&external_id=1&name=Trigun'));
    }

    public function testFillExistingLinksExternalIdAndFillsOnlyEmptyFieldsWithoutCreatingANewRecord(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        $existing = new TvAnime();
        $existing->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan)->setDurationMinutes(24);
        $this->entityManager->persist($existing);
        $this->entityManager->flush();
        $existingId = $existing->id ?? throw new \LogicException('must have id');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['title', 'episodesCount', 'durationMinutes']);
        $filler->method('findById')->willReturn(new PluginAnimeData(title: 'Trigun', episodesCount: 26, durationMinutes: 30));

        $controller = $this->createController(['animedb-shikimori' => $filler]);

        $response = $controller->fillExisting($existingId, Request::create('/anime/search-plugins/fill-existing/'.$existingId, 'POST', [
            'plugin_id' => 'animedb-shikimori',
            'external_id' => '1',
            '_token' => 'irrelevant',
        ]));

        $this->assertStringStartsWith('/anime_show?', $response->headers->get('Location') ?? '');
        $this->assertSame(1, $this->entityManager->getRepository(\App\Entity\Anime::class)->count([]));
        $this->assertSame('1', $existing->getCachedExternalId(new \App\Entity\ValueObject\PluginId('animedb-shikimori')));
        $this->assertSame(26, $existing->getEpisodesCount(), 'the empty episodesCount field must be filled in from the plugin');
        $this->assertSame(24, $existing->getDurationMinutes(), 'a durationMinutes already set on the record must not be overwritten by the plugin');
    }

    /**
     * When the plugin's findById() has nothing for this externalId any more (cache expired and
     * the lookup failed or returned null between the preview and this click),
     * BulkFillerService::fillExistingFromPlugin() returns FillResult::NotFound without linking
     * anything — the controller must send the user back to the search screen with an error
     * instead of silently redirecting to the (untouched) record.
     */
    public function testFillExistingRedirectsBackToSearchWithAnErrorWhenThePluginFindsNothing(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        $existing = new TvAnime();
        $existing->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($existing);
        $this->entityManager->flush();
        $existingId = $existing->id ?? throw new \LogicException('must have id');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['title']);
        $filler->method('findById')->willReturn(null);

        $controller = $this->createController(['animedb-shikimori' => $filler]);

        $response = $controller->fillExisting($existingId, Request::create('/anime/search-plugins/fill-existing/'.$existingId, 'POST', [
            'plugin_id' => 'animedb-shikimori',
            'external_id' => '1',
            'q' => 'Trigun',
            '_token' => 'irrelevant',
        ]));

        $location = $response->headers->get('Location') ?? '';
        $this->assertStringStartsWith('/anime_search_plugins?', $location);
        $this->assertStringContainsString('error=fill_not_found', $location);
        $this->assertStringContainsString('q=Trigun', $location, 'the search query must survive the redirect back to the search screen');
        $this->assertNull($existing->getCachedExternalId(new \App\Entity\ValueObject\PluginId('animedb-shikimori')), 'a NotFound result must not link the external id');
    }

    /**
     * Issue #848, point 3: when ($pluginId, $externalId) was claimed by a different Anime between
     * the preview and this click, {@see BulkFillerService::fillExistingFromPlugin()} throws
     * {@see ExternalIdAlreadyClaimedException} rather than returning a result. Before this issue
     * the controller turned that into a bare ConflictHttpException (a 409 page with no way back);
     * it must now redirect to the search screen with an error code, the owning anime's id, and the
     * original query intact, and must not touch $anime at all.
     */
    public function testFillExistingRedirectsToSearchWithConflictClaimedErrorWhenTheExternalIdAlreadyBelongsToAnotherAnime(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['title']);
        $filler->method('findById')->willReturn(new PluginAnimeData(title: 'Trigun'));

        $controller = $this->createController(['animedb-shikimori' => $filler]);

        // Anime #1 claims external id "1" for this plugin via the ordinary add() flow.
        $controller->add(Request::create('/anime/search-plugins/add', 'POST', [
            'plugin_id' => 'animedb-shikimori',
            'external_id' => '1',
            'name' => 'Trigun',
            '_token' => 'irrelevant',
        ]));
        /** @var \App\Entity\Anime $owner */
        $owner = $this->entityManager->getRepository(\App\Entity\Anime::class)->findAll()[0];
        $ownerId = $owner->id ?? throw new \LogicException('must have id');

        $target = new TvAnime();
        $target->setTitle('Some other title')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($target);
        $this->entityManager->flush();
        $targetId = $target->id ?? throw new \LogicException('must have id');

        $response = $controller->fillExisting($targetId, Request::create('/anime/search-plugins/fill-existing/'.$targetId, 'POST', [
            'plugin_id' => 'animedb-shikimori',
            'external_id' => '1',
            'q' => 'Trigun',
            '_token' => 'irrelevant',
        ]));

        $location = $response->headers->get('Location') ?? '';
        $this->assertStringStartsWith('/anime_search_plugins?', $location);
        $this->assertStringContainsString('error=conflict_claimed', $location);
        $this->assertStringContainsString('owner_id='.$ownerId, $location);
        $this->assertStringContainsString('q=Trigun', $location);
        $this->assertNull($target->getCachedExternalId(new \App\Entity\ValueObject\PluginId('animedb-shikimori')), 'the target anime must not be linked when the external id was already claimed');
    }

    /**
     * Issue #848, point 3: when $anime already carries a *different* external id for this plugin,
     * {@see BulkFillerService::fillExistingFromPlugin()} throws
     * {@see AnimeAlreadyLinkedToDifferentExternalIdException}. The controller must redirect to the
     * search screen with its own error code, the plugin id, and the original query, and must not
     * overwrite the record's existing link.
     */
    public function testFillExistingRedirectsToSearchWithConflictLinkedOtherErrorWhenAnimeAlreadyHasADifferentExternalId(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        $existing = new TvAnime();
        $existing->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($existing);
        $this->entityManager->flush();
        $existingId = $existing->id ?? throw new \LogicException('must have id');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['title']);
        $filler->method('findById')->willReturn(new PluginAnimeData(title: 'Trigun'));

        $controller = $this->createController(['animedb-shikimori' => $filler]);

        // Link the record to external id "1" first.
        $controller->fillExisting($existingId, Request::create('/anime/search-plugins/fill-existing/'.$existingId, 'POST', [
            'plugin_id' => 'animedb-shikimori',
            'external_id' => '1',
            '_token' => 'irrelevant',
        ]));

        // Attempting to link it to a different external id for the same plugin must be refused.
        $response = $controller->fillExisting($existingId, Request::create('/anime/search-plugins/fill-existing/'.$existingId, 'POST', [
            'plugin_id' => 'animedb-shikimori',
            'external_id' => '2',
            'q' => 'Trigun',
            '_token' => 'irrelevant',
        ]));

        $location = $response->headers->get('Location') ?? '';
        $this->assertStringStartsWith('/anime_search_plugins?', $location);
        $this->assertStringContainsString('error=conflict_linked_other', $location);
        $this->assertStringContainsString('plugin_id=animedb-shikimori', $location);
        $this->assertStringContainsString('q=Trigun', $location);
        $this->assertSame('1', $existing->getCachedExternalId(new \App\Entity\ValueObject\PluginId('animedb-shikimori')), 'the original link must survive the rejected attempt to relink');
    }

    /**
     * Issue #848, point 3: index() turns `?error=conflict_claimed&owner_id=<id>` into a link to
     * the owning record, resolved from the database right there rather than trusting a title
     * carried in the URL.
     */
    public function testIndexResolvesConflictClaimedErrorIntoALinkToTheOwningAnime(): void
    {
        $existing = new TvAnime();
        $existing->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($existing);
        $this->entityManager->flush();
        $existingId = $existing->id ?? throw new \LogicException('must have id');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/search_plugins/index.html.twig', $this->callback(static function (array $params) use ($existingId): bool {
                self::assertSame('search_plugins.error_conflict_claimed', $params['error']['messageKey']);
                self::assertNotNull($params['error']['link']);
                self::assertSame('/anime_show?id='.$existingId, $params['error']['link']['url']);
                self::assertSame('search_plugins.error_conflict_claimed_link', $params['error']['link']['labelKey']);

                return true;
            }))
            ->willReturn('<main></main>');

        $controller = $this->createController([], $twig);
        $controller->index(Request::create('/anime/search-plugins?error=conflict_claimed&owner_id='.$existingId));
    }

    /**
     * Issue #848, point 3: when `owner_id` no longer resolves to a record - deleted between the
     * redirect and this request, or simply bogus - index() must still show the translated error
     * message but degrade to no link, rather than link to a record that is not there.
     */
    public function testIndexConflictClaimedErrorHasNoLinkWhenTheOwningAnimeDoesNotExist(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/search_plugins/index.html.twig', $this->callback(static function (array $params): bool {
                self::assertSame('search_plugins.error_conflict_claimed', $params['error']['messageKey']);
                self::assertNull($params['error']['link']);

                return true;
            }))
            ->willReturn('<main></main>');

        $controller = $this->createController([], $twig);
        $controller->index(Request::create('/anime/search-plugins?error=conflict_claimed&owner_id=999999'));
    }

    /**
     * Issue #848, point 3: index() turns `?error=conflict_linked_other&plugin_id=<id>` into the
     * plugin's own display name (resolved from its manifest, same as group()'s heading does),
     * not the raw plugin id.
     */
    public function testIndexResolvesConflictLinkedOtherErrorIntoThePluginsDisplayName(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/search_plugins/index.html.twig', $this->callback(static function (array $params): bool {
                self::assertSame('search_plugins.error_conflict_linked_other', $params['error']['messageKey']);
                self::assertSame(['%plugin%' => 'Shikimori'], $params['error']['messageParams']);
                self::assertNull($params['error']['link']);

                return true;
            }))
            ->willReturn('<main></main>');

        $controller = $this->createController([], $twig);
        $controller->index(Request::create('/anime/search-plugins?error=conflict_linked_other&plugin_id=animedb-shikimori'));
    }

    public function testFillExistingRejectsInvalidCsrfToken(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        $existing = new TvAnime();
        $existing->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($existing);
        $this->entityManager->flush();
        $existingId = $existing->id ?? throw new \LogicException('must have id');

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = $this->createController([], csrfTokenManager: $csrf);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $controller->fillExisting($existingId, Request::create('/anime/search-plugins/fill-existing/'.$existingId, 'POST', [
            'plugin_id' => 'animedb-shikimori',
            'external_id' => '1',
            '_token' => 'bad',
        ]));
    }

    public function testAddRejectsInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = $this->createController([], csrfTokenManager: $csrf);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $controller->add(Request::create('/anime/search-plugins/add', 'POST', [
            'plugin_id' => 'animedb-shikimori',
            'external_id' => '1',
            'name' => 'Trigun',
            '_token' => 'bad',
        ]));
    }

    /**
     * Issue #833, points 5/6: a candidate whose (pluginId, externalId) already resolves to a
     * catalog record (here, linked by a prior add()) must preview as 'already_in_catalog' with a
     * link to that record, not fall through to 'new'/'possible_match'.
     */
    public function testPreviewShowsAlreadyInCatalogLinkWhenTheExternalIdIsAlreadyLinked(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['title']);
        $filler->method('findById')->willReturn(new PluginAnimeData(title: 'Trigun'));

        $this->createController(['animedb-shikimori' => $filler])->add(Request::create('/anime/search-plugins/add', 'POST', [
            'plugin_id' => 'animedb-shikimori',
            'external_id' => '1',
            'name' => 'Trigun',
            '_token' => 'irrelevant',
        ]));

        /** @var \App\Entity\Anime $existing */
        $existing = $this->entityManager->getRepository(\App\Entity\Anime::class)->findAll()[0];
        $existingId = $existing->id ?? throw new \LogicException('must have id');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/search_plugins/_preview.html.twig', $this->callback(static function (array $params) use ($existingId): bool {
                self::assertSame('already_in_catalog', $params['state']);
                self::assertSame($existingId, $params['existingAnimeId']);

                return true;
            }))
            ->willReturn('<div></div>');

        $controller = $this->createController(['animedb-shikimori' => $filler], $twig);
        $controller->preview(Request::create('/anime/search-plugins/preview?plugin_id=animedb-shikimori&external_id=1&name=Trigun'));
    }

    /**
     * Issue #833, point 6: "Create new" is the ordinary add() flow — it must still create a
     * second record even though a possible-match suggestion exists for the same normalized name.
     */
    public function testCreateNewViaAddCreatesASecondRecordDespiteAPossibleMatch(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        $existing = new TvAnime();
        $existing->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['title']);
        $filler->method('findById')->willReturn(new PluginAnimeData(title: 'Trigun'));

        $controller = $this->createController(['animedb-shikimori' => $filler]);

        $controller->add(Request::create('/anime/search-plugins/add', 'POST', [
            'plugin_id' => 'animedb-shikimori',
            'external_id' => '1',
            'name' => 'Trigun',
            '_token' => 'irrelevant',
        ]));

        $this->assertSame(2, $this->entityManager->getRepository(\App\Entity\Anime::class)->count([]));
    }

    /**
     * Issue #848, point 4 (review fix): a plain (non-htmx) GET at this fragment endpoint - a
     * stale browser-history entry from before this fix, or a direct link - must redirect to the
     * index screen with the same `q` rather than render a bare HTML fragment with no layout.
     */
    public function testResultsRedirectsAPlainNonHtmxRequestToTheIndexScreenWithTheQueryPreserved(): void
    {
        $controller = $this->createController([]);

        $response = $controller->results(Request::create('/anime/search-plugins/results?q=Trigun'));

        $this->assertSame('/anime_search_plugins?q=Trigun', $response->headers->get('Location'));
    }

    public function testResultsRedirectsAPlainNonHtmxRequestToTheIndexScreenWithoutAQueryParamWhenQIsEmpty(): void
    {
        $controller = $this->createController([]);

        $response = $controller->results(Request::create('/anime/search-plugins/results'));

        $this->assertSame('/anime_search_plugins', $response->headers->get('Location'));
    }

    /**
     * Issue #848, point 4 (review fix): the URL pushed into browser history for a search must be
     * this fragment endpoint's own caller, the index screen, with `q` set - never this fragment
     * endpoint's own URL, which has no layout, form, or styles to show on its own.
     */
    public function testResultsSetsHxPushUrlHeaderToTheIndexScreenUrlForHtmxRequests(): void
    {
        $controller = $this->createController([]);

        $response = $controller->results(Request::create(
            '/anime/search-plugins/results?q=Trigun',
            'GET',
            [],
            [],
            [],
            ['HTTP_HX_REQUEST' => 'true'],
        ));

        $this->assertSame('/anime_search_plugins?q=Trigun', $response->headers->get('HX-Push-Url'));
        $this->assertNull($response->headers->get('Location'));
    }

    public function testResultsHxPushUrlHeaderHasNoQueryParamWhenQIsEmpty(): void
    {
        $controller = $this->createController([]);

        $response = $controller->results(Request::create(
            '/anime/search-plugins/results',
            'GET',
            [],
            [],
            [],
            ['HTTP_HX_REQUEST' => 'true'],
        ));

        $this->assertSame('/anime_search_plugins', $response->headers->get('HX-Push-Url'));
    }

    public function testIndexShowsUnavailableStateWhenNoFillerPluginIsInstalled(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/search_plugins/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['hasActiveFiller'] === false
                    && $params['noFillerState']['kind'] === 'not_installed',
            ))
            ->willReturn('<main></main>');

        $controller = $this->createController([], $twig);
        $controller->index(Request::create('/anime/search-plugins'));
    }
}
