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
use App\EventSubscriber\LocaleSubscriber;
use App\Repository\SyncReviewItemRepository;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\NearestBuiltInLocale;
use App\Service\Plugin\AvailableLocalesProvider;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Search\AnimeReindexService;
use App\Service\Search\AnimeSearchIndexer;
use App\Service\Sync\SyncReviewService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Meilisearch\Client;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

/**
 * Acceptance (issue #538 "Что делается" #3): POSTing a locale switch must render the settings
 * page in the new locale, not the one negotiated for the request that carried the switch itself.
 * `native/accept-language.js` still sends the *old* Accept-Language on this exact request (it only
 * updates config.json's own copy after this POST completes), so without
 * `SettingsController::setLocale()` syncing `$request` to the just-persisted locale, the request
 * driving this very page render stays stuck on the old one.
 */
final class SettingsControllerLocaleSwitchFunctionalTest extends KernelTestCase
{
    private string $configPath;
    private string $pluginsDir;
    private ?string $originalRuntimeDir;
    private ?string $originalPluginsDir;
    private ?string $originalPluginsConfigPath;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir().'/anime-settings-locale-switch-test-'.uniqid().'.json';

        $this->originalRuntimeDir = $_SERVER['APP_RUNTIME_DIR'] ?? null;
        $this->originalPluginsDir = $_SERVER['PLUGINS_DIR'] ?? null;
        $this->originalPluginsConfigPath = $_SERVER['PLUGINS_CONFIG_PATH'] ?? null;

        $runtimeDir = sys_get_temp_dir().'/anime-settings-locale-switch-test-runtime-'.uniqid();
        $this->pluginsDir = sys_get_temp_dir().'/anime-settings-locale-switch-test-plugins-'.uniqid();
        mkdir($runtimeDir, recursive: true);
        mkdir($this->pluginsDir, recursive: true);

        $_SERVER['APP_RUNTIME_DIR'] = $runtimeDir;
        $_SERVER['PLUGINS_DIR'] = $this->pluginsDir;
        $_SERVER['PLUGINS_CONFIG_PATH'] = $this->pluginsDir.'/plugins.json';
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
    }

    public function testPostingALocaleSwitchRendersTheResponseInTheNewLocale(): void
    {
        $this->writeGermanTranslationPluginFixture('acme-de-pack');
        $this->reconcilePlugins();

        // Starting point: the app is currently in "ru", the same locale native/accept-language.js
        // keeps sending on the request that is about to switch it to "de".
        file_put_contents($this->configPath, (string) json_encode(['locale' => 'ru']));

        $kernel = self::bootKernel();
        $session = new Session(new MockArraySessionStorage());

        $request = Request::create('/settings', 'POST');
        $request->setSession($session);
        $request->headers->set('Accept-Language', 'ru');

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);

        // Real kernel.request dispatch with the stale "ru" Accept-Language, exactly like the one
        // that precedes SettingsController::setLocale() on a real switch-to-"de" POST.
        /** @var LocaleSubscriber $localeSubscriber */
        $localeSubscriber = self::getContainer()->get(LocaleSubscriber::class);
        $localeSubscriber->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertSame('ru', $request->getLocale(), 'Precondition: negotiation from the stale header must still land on "ru".');

        $request->request->set('locale', 'de');
        $request->request->set('_token', $this->validCsrfToken());

        $controller = $this->createController();
        $response = $controller->setLocale($request);

        self::assertSame('de', $request->getLocale(), 'setLocale() must sync the request locale to the one it just persisted.');

        $html = (string) $response->getContent();
        self::assertStringContainsString('<html lang="de"', $html, 'The rendered document must reflect the new locale, not the stale one from Accept-Language.');
        self::assertStringContainsString('Einstellungen', $html, 'settings.title must resolve from the "de" plugin catalogue.');

        // settings.heading is not defined by the "de" plugin fixture above. Before setLocale()
        // recomputed the fallback chain, the translator was still holding the ["ru", "en"] chain
        // LocaleSubscriber set up from the stale "ru" Accept-Language, so this key resolved to its
        // Russian text ("Настройки") instead of falling through to English.
        self::assertStringContainsString('<h1>Settings</h1>', $html, 'A key missing from the "de" catalog must fall through to English, not the stale "ru" fallback chain.');
    }

    private function createController(): SettingsController
    {
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        /** @var CsrfTokenManagerInterface $csrfTokenManager */
        $csrfTokenManager = self::getContainer()->get(CsrfTokenManagerInterface::class);
        /** @var NearestBuiltInLocale $nearestBuiltInLocale */
        $nearestBuiltInLocale = self::getContainer()->get(NearestBuiltInLocale::class);
        /** @var Translator $translator */
        $translator = self::getContainer()->get('translator.default');

        return new SettingsController(
            $this->availableLocalesProvider(),
            new AppSettingsProvider(new AppConfigStore($this->configPath)),
            $csrfTokenManager,
            $twig,
            $this->createReindexService(),
            $this->createSyncReview(),
            $nearestBuiltInLocale,
            $translator,
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
