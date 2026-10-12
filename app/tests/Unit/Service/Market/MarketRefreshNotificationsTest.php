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

namespace App\Tests\Unit\Service\Market;

use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Market\MarketRefreshService;
use App\Service\Market\MarketSnapshotBuilder;
use App\Service\Market\MarketSnapshotCache;
use App\Service\Market\MarketUpdateNotifier;
use App\Service\Market\MarketUpdateResolver;
use App\Service\Market\PluginRegistry;
use App\Service\Market\PluginRegistryCache;
use App\Service\Market\PluginRegistryFetcher;
use App\Service\Market\PluginRegistryHighWaterMarkStore;
use App\Service\Market\PluginRegistryLoader;
use App\Service\Market\PluginRegistrySignatureVerifier;
use App\Service\NearestBuiltInLocale;
use App\Service\Notification\Kind\PluginUpdateNotificationKind;
use App\Service\Notification\NotificationCenter;
use App\Service\Notification\NotificationKindRegistry;
use App\Service\Notification\NotificationRenderer;
use App\Service\Notification\NotificationSubjectLinker;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\WsPublisher;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261009000000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

/**
 * {@see MarketRefreshService::refresh()} records a `plugin_update` notification per installed
 * plugin with a newer compatible version — through the real notification center and database.
 */
final class MarketRefreshNotificationsTest extends TestCase
{
    private string $dir;
    private Connection $connection;
    private string $registryJson = '';
    private bool $registryReachable = true;
    /** @var non-empty-string */
    private string $secretKey;
    private string $trustedPublicKey;
    private MarketRefreshService $service;
    private MarketSnapshotCache $snapshotCache;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/anime-market-refresh-notifications-'.uniqid();
        mkdir($this->dir.'/plugins/acme-widgets', recursive: true);
        file_put_contents($this->dir.'/plugins/acme-widgets/manifest.json', (string) json_encode($this->manifest('1.0.0')));
        (new InstalledPluginsRegistry($this->dir.'/plugins', new PluginsConfigStore($this->dir.'/plugins.json'), new NullLogger()))->reconcile();

        $keyPair = sodium_crypto_sign_keypair();
        $this->trustedPublicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));
        $this->secretKey = sodium_crypto_sign_secretkey($keyPair);

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        require_once \dirname(__DIR__, 4).'/migrations/Version20261009000000.php';
        $migration = new Version20261009000000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }

        $this->service = $this->buildService();
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    public function testSuccessfulRefreshRecordsAnUpdateNotificationForAnInstalledPlugin(): void
    {
        $this->serve(1, '1.1.0');

        $this->assertTrue($this->service->refresh());

        $row = $this->connection->fetchAssociative('SELECT kind, severity, params FROM notification');
        $this->assertSame(['plugin_update', 'info', ['name' => 'Acme widgets', 'version' => '1.1.0']], [$row['kind'] ?? null, $row['severity'] ?? null, json_decode((string) ($row['params'] ?? ''), true)]);
    }

    public function testNoNotificationWhenThereIsNothingNewer(): void
    {
        $this->serve(1, '1.0.0');

        $this->assertTrue($this->service->refresh());

        $this->assertSame(0, $this->rows());
    }

    public function testFailedRefreshRecordsNothing(): void
    {
        // A snapshot that already holds a newer version: only the success check keeps the notifier quiet.
        $this->serve(1, '1.1.0');
        $this->snapshotCache->store((new MarketSnapshotBuilder())->build(PluginRegistry::fromJson($this->registryJson, new NullLogger()), '2.5.0'));
        $this->registryReachable = false;

        $this->assertFalse($this->service->refresh());

        $this->assertSame(0, $this->rows());
    }

    public function testRefreshSkippedByTheBusyLockRecordsNothing(): void
    {
        $this->serve(1, '1.1.0');
        $this->snapshotCache->store((new MarketSnapshotBuilder())->build(PluginRegistry::fromJson($this->registryJson, new NullLogger()), '2.5.0'));
        $holder = fopen($this->dir.'/refresh.lock', 'c');
        $this->assertNotFalse($holder);
        flock($holder, \LOCK_EX);

        try {
            $this->assertTrue($this->service->refresh());
        } finally {
            flock($holder, \LOCK_UN);
            fclose($holder);
        }

        $this->assertSame(0, $this->rows());
    }

    public function testFailingNotificationDoesNotFailTheRefresh(): void
    {
        $this->serve(1, '1.1.0');
        // The notifier reads the installed-plugins index; an index that throws makes notify() throw.
        file_put_contents($this->dir.'/plugins/installed-plugins.php', "<?php throw new \\RuntimeException('broken index');");

        $this->assertTrue($this->service->refresh());

        $this->assertNotNull($this->snapshotCache->load());
        $this->assertNotNull((new AppConfigStore($this->dir.'/config.json'))->read()[MarketRefreshService::CONFIG_KEY_LAST_REFRESH_AT] ?? null);
    }

    public function testTheSameVersionNotifiesOnceEvenAfterTheRecordWasReadOrDeleted(): void
    {
        $this->serve(1, '1.1.0');
        $this->service->refresh();
        $this->connection->executeStatement('UPDATE notification SET read_at = 1');
        $this->serve(2, '1.1.0');
        $this->service->refresh();
        $this->assertSame(1, $this->rows());

        $this->connection->executeStatement('DELETE FROM notification');
        $this->serve(3, '1.1.0');
        $this->service->refresh();

        $this->assertSame(0, $this->rows());
    }

    public function testTheNextVersionOfTheSamePluginNotifiesAgain(): void
    {
        $this->serve(1, '1.1.0');
        $this->service->refresh();
        $this->serve(2, '1.2.0');
        $this->service->refresh();

        $this->assertSame(2, $this->rows());
    }

    private function rows(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM notification');
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(string $version): array
    {
        return [
            'id' => 'acme-widgets',
            'name' => 'Acme widgets',
            'version' => $version,
            'type' => 'integration',
            'features' => ['widget' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ];
    }

    private function serve(int $sequence, string $version): void
    {
        $this->registryJson = (string) json_encode([
            'sequence' => $sequence,
            'asset_mirrors' => [],
            'plugins' => [[
                'id' => 'acme-widgets',
                'manifest' => $this->manifest($version),
                'versions' => [['version' => $version, 'core' => '>=2.0.0', 'sha256' => 'sha-'.$version]],
            ]],
        ]);
    }

    private function buildService(): MarketRefreshService
    {
        $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (!$this->registryReachable) {
                throw new TransportException('Connection refused.');
            }

            return str_ends_with($url, '.sig')
                ? new MockResponse(base64_encode(sodium_crypto_sign_detached($this->registryJson, $this->secretKey)))
                : new MockResponse($this->registryJson);
        }, null);

        $kinds = new NotificationKindRegistry([new PluginUpdateNotificationKind()]);
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['notification.plugin_update.title' => 'T %name%', 'notification.plugin_update.body' => 'B %name% %version%'], 'en');
        $renderer = new NotificationRenderer($kinds, $translator, $this->createStub(AppSettingsProvider::class), new NearestBuiltInLocale(), $this->createStub(NotificationSubjectLinker::class), new NullLogger());
        $center = new NotificationCenter($this->connection, $kinds, $renderer, $this->createStub(WsPublisher::class), new NullLogger());

        $this->snapshotCache = $snapshotCache = new MarketSnapshotCache($this->dir.'/snapshot.json');
        $notifier = new MarketUpdateNotifier(
            new InstalledPluginsRegistry($this->dir.'/plugins', new PluginsConfigStore($this->dir.'/plugins.json'), new NullLogger()),
            new MarketUpdateResolver($snapshotCache, '2.5.0'),
            $center,
        );

        return new MarketRefreshService(
            new PluginRegistryLoader(
                new PluginRegistryFetcher($httpClient),
                new PluginRegistrySignatureVerifier([$this->trustedPublicKey]),
                new PluginRegistryCache($this->dir.'/registry.json', new NullLogger()),
                new PluginRegistryHighWaterMarkStore(new AppConfigStore($this->dir.'/config.json')),
                new NullLogger(),
            ),
            new MarketSnapshotBuilder(),
            $snapshotCache,
            new AppConfigStore($this->dir.'/config.json'),
            new NullLogger(),
            '2.5.0',
            $this->dir.'/refresh.lock',
            $notifier,
        );
    }
}
