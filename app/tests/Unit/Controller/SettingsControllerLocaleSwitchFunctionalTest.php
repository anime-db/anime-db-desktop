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

use App\Controller\SettingsController;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Repository\SyncReviewItemRepository;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Plugin\AvailableLocalesProvider;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Search\AnimeReindexService;
use App\Service\Search\AnimeSearchIndexer;
use App\Service\Sync\SyncReviewService;
use App\Service\WsPublisher;
use App\Tests\Support\TemporaryDirectories;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Meilisearch\Client;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

/**
 * Acceptance (issue #558): a locale switch is now Post/Redirect/Get — the POST itself only
 * persists the choice and redirects, and it's the *following* GET, negotiated from the
 * Accept-Language `native/accept-language.js` sends once config.json reflects the new locale,
 * that must render the settings page in the new language.
 */
final class SettingsControllerLocaleSwitchFunctionalTest extends KernelTestCase
{
    use TemporaryDirectories;

    private string $configPath;
    private string $pluginsDir;
    private ?string $originalRuntimeDir;
    private ?string $originalPluginsDir;
    private ?string $originalPluginsConfigPath;
    private ?string $originalCoreVersion;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir().'/anime-settings-locale-switch-test-'.uniqid().'.json';

        $this->originalRuntimeDir = $_SERVER['APP_RUNTIME_DIR'] ?? null;
        $this->originalPluginsDir = $_SERVER['PLUGINS_DIR'] ?? null;
        $this->originalPluginsConfigPath = $_SERVER['PLUGINS_CONFIG_PATH'] ?? null;
        $this->originalCoreVersion = $_SERVER['CORE_VERSION'] ?? null;

        $runtimeDir = $this->createTemporaryDirectory('anime-settings-locale-switch-test-runtime-');
        $this->pluginsDir = $this->createTemporaryDirectory('anime-settings-locale-switch-test-plugins-');

        $_SERVER['APP_RUNTIME_DIR'] = $runtimeDir;
        $_SERVER['PLUGINS_DIR'] = $this->pluginsDir;
        $_SERVER['PLUGINS_CONFIG_PATH'] = $this->pluginsDir.'/plugins.json';
        // Mocks the same Electron-supplied channel native/supervisor/env.js sets in production
        // (issue #565) — without it, Kernel::coreVersion() would fall back to this checkout's own
        // package.json version, which does not satisfy the fixture manifest's `require.core`
        // below and would keep its plugin bundle from registering at all.
        $_SERVER['CORE_VERSION'] = '2.0.0';
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ([$this->configPath, $this->configPath.'.tmp', $this->configPath.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->restoreServerVar('APP_RUNTIME_DIR', $this->originalRuntimeDir);
        $this->restoreServerVar('PLUGINS_DIR', $this->originalPluginsDir);
        $this->restoreServerVar('PLUGINS_CONFIG_PATH', $this->originalPluginsConfigPath);
        $this->restoreServerVar('CORE_VERSION', $this->originalCoreVersion);

        $this->removeTemporaryDirectories();
    }

    public function testPostingALocaleSwitchRedirectsAndTheFollowingGetRendersTheNewLocale(): void
    {
        $this->writeGermanTranslationPluginFixture('acme-de-pack');
        $this->reconcilePlugins();

        // Starting point: the app is currently in "ru", the same locale native/accept-language.js
        // keeps sending on the request that is about to switch it to "de".
        file_put_contents($this->configPath, (string) json_encode(['locale' => 'ru']));

        $kernel = self::bootKernel();
        $session = new Session(new MockArraySessionStorage());

        /** @var EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = self::getContainer()->get('event_dispatcher');

        $postRequest = Request::create('/settings', 'POST');
        $postRequest->setSession($session);
        $postRequest->headers->set('Accept-Language', 'ru');

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($postRequest);

        // Real kernel.request dispatch with the stale "ru" Accept-Language, exactly like the one
        // that precedes SettingsController::setLocale() on a real switch-to-"de" POST. Dispatching
        // through the actual event dispatcher — not just LocaleSubscriber::onKernelRequest()
        // directly — also runs Symfony's own LocaleAwareListener (priority 15, right after
        // LocaleSubscriber's 20), which is what actually syncs translator.default's locale from
        // the negotiated $request->getLocale() on a real request.
        $eventDispatcher->dispatch(new RequestEvent($kernel, $postRequest, HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
        self::assertSame('ru', $postRequest->getLocale(), 'Precondition: negotiation from the stale header must still land on "ru".');

        $postRequest->request->set('locale', 'de');
        $postRequest->request->set('_token', $this->validCsrfToken());

        $controller = $this->createController();
        $response = $controller->setLocale($postRequest);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(303, $response->getStatusCode());
        /** @var UrlGeneratorInterface $urlGenerator */
        $urlGenerator = self::getContainer()->get(UrlGeneratorInterface::class);
        self::assertSame($urlGenerator->generate('settings_index'), $response->getTargetUrl());

        $persisted = json_decode((string) file_get_contents($this->configPath), true);
        self::assertSame('de', $persisted['locale'], 'The POST must persist the new locale before redirecting.');

        $requestStack->pop();

        // The following GET: native/accept-language.js now reads config.json after this write and
        // sends "de", so this request carries the header the redirect's follow-up navigation would
        // actually receive — unlike the POST above, deliberately not the stale one.
        $getRequest = Request::create('/settings');
        $getRequest->setSession($session);
        $getRequest->headers->set('Accept-Language', 'de');
        $requestStack->push($getRequest);

        $eventDispatcher->dispatch(new RequestEvent($kernel, $getRequest, HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
        self::assertSame('de', $getRequest->getLocale(), 'The follow-up GET must negotiate "de" on its own, with no help from setLocale().');

        $html = (string) $this->createController()->index()->getContent();
        self::assertStringContainsString('<html lang="de"', $html, 'The rendered document must reflect the new locale.');
        self::assertStringContainsString('Einstellungen', $html, 'settings.title must resolve from the "de" plugin catalogue.');

        // settings.heading is not defined by the "de" plugin fixture above. LocaleSubscriber must
        // have recomputed the translator's fallback chain from this request's own negotiation, not
        // from whatever chain a previous request left behind.
        self::assertStringContainsString('<h1>Settings</h1>', $html, 'A key missing from the "de" catalog must fall through to English.');
    }

    private function createController(): SettingsController
    {
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        /** @var CsrfTokenManagerInterface $csrfTokenManager */
        $csrfTokenManager = self::getContainer()->get(CsrfTokenManagerInterface::class);
        /** @var UrlGeneratorInterface $urlGenerator */
        $urlGenerator = self::getContainer()->get(UrlGeneratorInterface::class);
        /** @var WsPublisher $wsPublisher */
        $wsPublisher = self::getContainer()->get(WsPublisher::class);

        return new SettingsController(
            $this->availableLocalesProvider(),
            new AppSettingsProvider(new AppConfigStore($this->configPath)),
            $wsPublisher,
            $csrfTokenManager,
            $twig,
            $this->createReindexService(),
            $this->createSyncReview(),
            $urlGenerator,
        );
    }

    private function availableLocalesProvider(): AvailableLocalesProvider
    {
        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );

        return new AvailableLocalesProvider($registry, ['en', 'ru']);
    }

    private function createReindexService(): AnimeReindexService
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
        $entityManager = new EntityManager($connection, $config);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        return new AnimeReindexService($entityManager, new AnimeSearchIndexer($this->createStub(Client::class)));
    }

    private function createSyncReview(): SyncReviewService
    {
        $repository = $this->createStub(SyncReviewItemRepository::class);
        $repository->method('findAllUnresolvedOrderedByCreatedAt')->willReturn([]);

        return new SyncReviewService($repository);
    }

    /**
     * Generated through the same container-provided {@see CsrfTokenManagerInterface} instance
     * {@see self::createController()} injects into the controller, backed by the session already
     * pushed onto the request stack — so the token this produces is one the controller's own
     * {@see CsrfTokenManagerInterface::isTokenValid()} call will accept.
     */
    private function validCsrfToken(): string
    {
        /** @var CsrfTokenManagerInterface $csrfTokenManager */
        $csrfTokenManager = self::getContainer()->get(CsrfTokenManagerInterface::class);

        return $csrfTokenManager->getToken('settings_set_locale')->getValue();
    }

    private function writeGermanTranslationPluginFixture(string $pluginId): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir.'/translations', recursive: true);

        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => ucfirst($pluginId),
            'version' => '1.0.0',
            'type' => 'translation',
            'locales' => ['de'],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        file_put_contents($dir.'/translations/messages.de.yaml', "settings:\n    title: 'Einstellungen'\n");
    }

    private function reconcilePlugins(): void
    {
        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
        $registry->reconcile();
    }

    private function restoreServerVar(string $key, ?string $original): void
    {
        if ($original === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $original;
        }
    }
}
