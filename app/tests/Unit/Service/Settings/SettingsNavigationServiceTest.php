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

namespace App\Tests\Unit\Service\Settings;

use App\Repository\SyncReviewItemRepository;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SettingsPageRegistry;
use App\Service\Settings\SettingsNavigationService;
use App\Service\Sync\SyncReviewService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SettingsNavigationServiceTest extends TestCase
{
    private string $pluginsDir;
    private InstalledPluginsRegistry $installedPlugins;

    protected function setUp(): void
    {
        $this->pluginsDir = sys_get_temp_dir().'/anime-settings-nav-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);

        $this->installedPlugins = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginsDir);
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

    private function urlGenerator(): UrlGeneratorInterface
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route, array $params = []): string => '/'.$route.(($params === []) ? '' : '?'.http_build_query($params)),
        );

        return $urlGenerator;
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }

    /** @param array<string, string> $attributes */
    private function requestStack(?string $routeName = null, array $attributes = []): RequestStack
    {
        $requestStack = new RequestStack();
        if ($routeName !== null) {
            $request = Request::create('/');
            $request->attributes->set('_route', $routeName);
            foreach ($attributes as $key => $value) {
                $request->attributes->set($key, $value);
            }
            $requestStack->push($request);
        }

        return $requestStack;
    }

    private function syncReview(int $count = 0): SyncReviewService
    {
        $repository = $this->createStub(SyncReviewItemRepository::class);
        $repository->method('countUnresolvedByKind')->willReturn($count);

        return new SyncReviewService($repository);
    }

    private function service(
        ?RequestStack $requestStack = null,
        ?SettingsPageRegistry $settingsPages = null,
        ?SyncReviewService $syncReview = null,
        ?LoggerInterface $logger = null,
    ): SettingsNavigationService {
        return new SettingsNavigationService(
            $requestStack ?? $this->requestStack(),
            $this->urlGenerator(),
            $this->translator(),
            $this->installedPlugins,
            $settingsPages ?? new SettingsPageRegistry([], $this->installedPlugins),
            $syncReview ?? $this->syncReview(),
            $logger ?? new NullLogger(),
        );
    }

    public function testTheItemMatchingTheCurrentRouteIsMarkedActive(): void
    {
        $groups = $this->service(requestStack: $this->requestStack('settings_backup_index'))->groups();

        $catalog = $this->findGroupContaining($groups, 'backup');
        $backupItem = $this->findItem($catalog, 'backup');

        self::assertTrue($backupItem->active);
        self::assertFalse($this->findItem($catalog, 'labels')->active);
    }

    public function testARouteAbsentFromTheMapLeavesEveryItemInactive(): void
    {
        $groups = $this->service(requestStack: $this->requestStack('a_plugin_declared_route'))->groups();

        foreach ($groups as $group) {
            foreach ($group->items as $item) {
                self::assertFalse($item->active, \sprintf('Item "%s" must not be active for an unmapped route.', $item->id));
            }
        }
    }

    /**
     * `settings_plugin_page` cannot be a static entry in the route map (issue #822) since it
     * covers every plugin's settings page — the active item depends on the `pluginId` route
     * attribute instead.
     */
    public function testThePluginPageRouteResolvesTheActiveItemByPluginId(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->installedPlugins->reconcile();

        $settingsPages = $this->createStub(SettingsPageRegistry::class);
        $settingsPages->method('enabledPluginIdsWithSettingsPage')->willReturn(['animedb-shikimori']);

        $requestStack = $this->requestStack('settings_plugin_page', ['pluginId' => 'animedb-shikimori']);

        $groups = $this->service(requestStack: $requestStack, settingsPages: $settingsPages)->groups();

        $pluginGroup = $this->findGroupContaining($groups, 'plugin:animedb-shikimori');
        $item = $this->findItem($pluginGroup, 'plugin:animedb-shikimori');

        self::assertTrue($item->active);
        self::assertSame('Shikimori', $item->label);
    }

    /**
     * Acceptance (issue #822): a settings page whose plugin-settings lookup fails must not take
     * `/settings/backup` — or any other settings page — down with it. Building the group is
     * wrapped on its own; on failure the group is simply omitted (and the failure logged), the
     * rest of the sidebar renders normally.
     */
    public function testABrokenPluginSettingsLookupOmitsTheGroupInsteadOfBreakingTheSidebar(): void
    {
        $settingsPages = $this->createStub(SettingsPageRegistry::class);
        $settingsPages->method('enabledPluginIdsWithSettingsPage')->willThrowException(new \RuntimeException('plugin settings page registry is broken'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $groups = $this->service(
            requestStack: $this->requestStack('settings_backup_index'),
            settingsPages: $settingsPages,
            logger: $logger,
        )->groups();

        foreach ($groups as $group) {
            foreach ($group->items as $item) {
                self::assertStringStartsNotWith('plugin:', $item->id);
            }
        }

        // Every other group still renders — the page is a normal 200, not a 500. findGroupContaining()
        // fails the test itself if no such group exists, so reaching the next line is the assertion.
        $this->findGroupContaining($groups, 'backup');
    }

    public function testTheNeedsCorrectionBadgeReflectsTheUnresolvedCount(): void
    {
        $groups = $this->service(syncReview: $this->syncReview(3))->groups();

        $item = $this->findItem($this->findGroupContaining($groups, 'sync_review'), 'sync_review');

        self::assertSame(3, $item->badge);
    }

    public function testTheNeedsCorrectionBadgeIsOmittedRatherThanCrashingWhenTheCountQueryFails(): void
    {
        $repository = $this->createStub(SyncReviewItemRepository::class);
        $repository->method('countUnresolvedByKind')->willThrowException(new \RuntimeException('database is unavailable'));

        $groups = $this->service(syncReview: new SyncReviewService($repository))->groups();

        $item = $this->findItem($this->findGroupContaining($groups, 'sync_review'), 'sync_review');

        self::assertNull($item->badge);
    }

    /** @param list<\App\Service\Settings\SettingsNavGroup> $groups */
    private function findGroupContaining(array $groups, string $itemId): \App\Service\Settings\SettingsNavGroup
    {
        foreach ($groups as $group) {
            foreach ($group->items as $item) {
                if ($item->id === $itemId) {
                    return $group;
                }
            }
        }

        self::fail(\sprintf('No group contains an item with id "%s".', $itemId));
    }

    private function findItem(\App\Service\Settings\SettingsNavGroup $group, string $itemId): \App\Service\Settings\SettingsNavItem
    {
        foreach ($group->items as $item) {
            if ($item->id === $itemId) {
                return $item;
            }
        }

        self::fail(\sprintf('Group "%s" has no item with id "%s".', $group->label, $itemId));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
