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

namespace App\Tests\Acceptance;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Extends the #557 kernel-level acceptance style ({@see SettingsProxyLocalizationTest},
 * {@see PluginLocaleSpellingNormalizationTest}) to the locale switch itself (issue #558): a POST
 * to `/settings` and the follow-up GET both go through `$kernel->handle()`, so routing is
 * actually exercised. `SettingsControllerLocaleSwitchFunctionalTest` calls
 * `SettingsController::setLocale()`/`index()` directly and cannot catch a routing regression —
 * both actions share the `/settings` path and differ only by HTTP method, so a method/route mixup
 * would pass that test while breaking every real request.
 *
 * `settings/index.html.twig` also queries {@see \App\Repository\SyncReviewItemRepository} through
 * the container's real entity manager (unlike `/settings/proxy`, which the other kernel-level
 * acceptance tests use precisely to avoid this), so `DATABASE_URL` is pointed at a throwaway
 * SQLite file with its schema created up front — otherwise the GET below would fail on a missing
 * table rather than exercising the locale switch. `QUEUE_DATABASE_URL` is overridden alongside it
 * for the same reason: creating the ORM schema also runs Symfony Messenger's Doctrine schema
 * listener, which opens that connection too.
 */
final class SettingsLocaleSwitchAcceptanceTest extends KernelTestCase
{
    private string $configPath;
    private string $runtimeDir;
    private string $pluginsDir;
    private string $databasePath;
    private string $queueDatabasePath;
    private ?string $originalConfigPath;
    private ?string $originalRuntimeDir;
    private ?string $originalPluginsDir;
    private ?string $originalPluginsConfigPath;
    private ?string $originalDatabaseUrl;
    private ?string $originalQueueDatabaseUrl;
    private ?string $originalDatabaseUrlEnv;
    private ?string $originalQueueDatabaseUrlEnv;

    protected function setUp(): void
    {
        $this->originalConfigPath = $_SERVER['CONFIG_PATH'] ?? null;
        $this->originalRuntimeDir = $_SERVER['APP_RUNTIME_DIR'] ?? null;
        $this->originalPluginsDir = $_SERVER['PLUGINS_DIR'] ?? null;
        $this->originalPluginsConfigPath = $_SERVER['PLUGINS_CONFIG_PATH'] ?? null;
        $this->originalDatabaseUrl = $_SERVER['DATABASE_URL'] ?? null;
        $this->originalQueueDatabaseUrl = $_SERVER['QUEUE_DATABASE_URL'] ?? null;
        // DATABASE_URL/QUEUE_DATABASE_URL (unlike the other overrides above) have defaults in
        // .env, so tests/bootstrap.php's Dotenv::bootEnv() already populated $_ENV with them
        // before this test runs. Symfony's container resolves %env(...)% from $_ENV before
        // $_SERVER, so overriding $_SERVER alone here is silently ignored.
        $this->originalDatabaseUrlEnv = $_ENV['DATABASE_URL'] ?? null;
        $this->originalQueueDatabaseUrlEnv = $_ENV['QUEUE_DATABASE_URL'] ?? null;

        $this->configPath = sys_get_temp_dir().'/anime-settings-locale-switch-acceptance-'.uniqid().'.json';
        $this->runtimeDir = sys_get_temp_dir().'/anime-settings-locale-switch-acceptance-runtime-'.uniqid();
        $this->pluginsDir = sys_get_temp_dir().'/anime-settings-locale-switch-acceptance-plugins-'.uniqid();
        $this->databasePath = sys_get_temp_dir().'/anime-settings-locale-switch-acceptance-db-'.uniqid().'.sqlite';
        $this->queueDatabasePath = sys_get_temp_dir().'/anime-settings-locale-switch-acceptance-queue-'.uniqid().'.sqlite';
        mkdir($this->runtimeDir, recursive: true);
        mkdir($this->pluginsDir, recursive: true);

        $_SERVER['CONFIG_PATH'] = $this->configPath;
        $_SERVER['APP_RUNTIME_DIR'] = $this->runtimeDir;
        $_SERVER['PLUGINS_DIR'] = $this->pluginsDir;
        $_SERVER['PLUGINS_CONFIG_PATH'] = $this->pluginsDir.'/plugins.json';
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///'.$this->databasePath;
        $_SERVER['QUEUE_DATABASE_URL'] = $_ENV['QUEUE_DATABASE_URL'] = 'sqlite:///'.$this->queueDatabasePath;

        file_put_contents($this->configPath, (string) json_encode(['locale' => 'ru']));
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ([$this->configPath, $this->configPath.'.tmp', $this->configPath.'.lock', $this->databasePath, $this->queueDatabasePath] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->restoreServerVar('CONFIG_PATH', $this->originalConfigPath);
        $this->restoreServerVar('APP_RUNTIME_DIR', $this->originalRuntimeDir);
        $this->restoreServerVar('PLUGINS_DIR', $this->originalPluginsDir);
        $this->restoreServerVar('PLUGINS_CONFIG_PATH', $this->originalPluginsConfigPath);
        $this->restoreServerVar('DATABASE_URL', $this->originalDatabaseUrl);
        $this->restoreServerVar('QUEUE_DATABASE_URL', $this->originalQueueDatabaseUrl);
        $this->restoreEnvVar('DATABASE_URL', $this->originalDatabaseUrlEnv);
        $this->restoreEnvVar('QUEUE_DATABASE_URL', $this->originalQueueDatabaseUrlEnv);
    }

    public function testPostingALocaleSwitchThroughTheKernelRedirectsAndTheFollowingGetRendersTheNewLocale(): void
    {
        $kernel = self::bootKernel();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $session = new Session(new MockArraySessionStorage());

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        /** @var CsrfTokenManagerInterface $csrfTokenManager */
        $csrfTokenManager = self::getContainer()->get(CsrfTokenManagerInterface::class);

        $postRequest = Request::create('/settings', 'POST', ['locale' => 'en']);
        $postRequest->setSession($session);
        $postRequest->headers->set('Accept-Language', 'ru');

        // The token manager resolves the current session through the request stack, so a request
        // carrying the session must be on the stack before a token can be minted for it — the
        // same sequencing SettingsControllerLocaleSwitchFunctionalTest::validCsrfToken() relies on.
        $requestStack->push($postRequest);
        $token = $csrfTokenManager->getToken('settings_set_locale')->getValue();
        $requestStack->pop();
        $postRequest->request->set('_token', $token);

        $postResponse = $kernel->handle($postRequest);

        self::assertSame(303, $postResponse->getStatusCode(), 'A locale switch must redirect (Post/Redirect/Get), not render in place.');
        /** @var UrlGeneratorInterface $urlGenerator */
        $urlGenerator = self::getContainer()->get(UrlGeneratorInterface::class);
        self::assertSame(
            $urlGenerator->generate('settings_index'),
            $postResponse->headers->get('Location'),
            'The redirect must point back at settings_index.',
        );

        $persisted = json_decode((string) file_get_contents($this->configPath), true);
        self::assertSame('en', $persisted['locale'], 'The POST must persist the new locale before redirecting.');

        // The follow-up navigation: native/accept-language.js has re-read config.json by now and
        // sends the persisted "en", independently of the session the POST used above.
        $getRequest = Request::create('/settings');
        $getRequest->headers->set('Accept-Language', 'en');

        $getResponse = $kernel->handle($getRequest);
        $body = (string) $getResponse->getContent();

        self::assertStringContainsString('<html lang="en"', $body, 'The rendered document must reflect the new locale.');
        self::assertStringContainsString('<h1>Settings</h1>', $body, 'settings.heading must resolve from the "en" catalogue.');
    }

    private function restoreServerVar(string $key, ?string $original): void
    {
        if ($original === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $original;
        }
    }

    private function restoreEnvVar(string $key, ?string $original): void
    {
        if ($original === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $original;
        }
    }
}
