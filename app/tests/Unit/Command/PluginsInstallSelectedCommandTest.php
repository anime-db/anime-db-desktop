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

namespace App\Tests\Unit\Command;

use AnimeDb\PluginContracts\Manifest\ManifestValidationError;
use App\Command\PluginsInstallSelectedCommand;
use App\Entity\ValueObject\PluginId;
use App\Service\Market\Exception\IncompatibleCoreSnapshotException;
use App\Service\Market\Exception\IncompatiblePluginContractsSnapshotException;
use App\Service\Market\Exception\MarketSnapshotUnavailableException;
use App\Service\Market\Exception\PluginAssetDownloadException;
use App\Service\Market\Exception\PluginNotInSnapshotException;
use App\Service\Market\Exception\UnknownPluginVersionException;
use App\Service\Market\InstallerPluginsSelection;
use App\Service\Market\MarketPluginInstallerInterface;
use App\Service\Plugin\Exception\IncompatiblePluginContractsVersionException;
use App\Service\Plugin\Exception\IncompatiblePluginCoreVersionException;
use App\Service\Plugin\Exception\InvalidInstalledPluginException;
use App\Service\Plugin\Exception\PluginAlreadyInstalledException;
use App\Service\Plugin\Exception\PluginCacheWarmupException;
use App\Service\Plugin\Exception\PluginInstallException;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Tests\Support\TemporaryDirectories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PluginsInstallSelectedCommandTest extends TestCase
{
    use TemporaryDirectories;

    private string $selectionPath;
    private InstalledPluginsRegistry $registry;
    /** @var AbstractLogger&object{logs: list<array{level: string, message: string}>} */
    private AbstractLogger $logger;

    protected function setUp(): void
    {
        $root = $this->createTemporaryDirectory('anime-install-selected-test-');
        $this->selectionPath = $root.'/installer-plugins.json';
        mkdir($root.'/plugins');
        $this->registry = new InstalledPluginsRegistry($root.'/plugins', new PluginsConfigStore($root.'/plugins/plugins.json'), new \Psr\Log\NullLogger());

        $this->logger = new class extends AbstractLogger {
            /** @var list<array{level: string, message: string}> */
            public array $logs = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logs[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->removeTemporaryDirectories();
    }

    /**
     * @param \Closure(PluginId, bool, bool): PluginId $behavior
     */
    private function runCommand(\Closure $behavior): CommandTester
    {
        $installer = new class($behavior) implements MarketPluginInstallerInterface {
            public function __construct(private readonly \Closure $behavior)
            {
            }

            public function installOrUpdate(PluginId $id, bool $update = false, bool $publishWorkersReload = true): PluginId
            {
                return ($this->behavior)($id, $update, $publishWorkersReload);
            }
        };

        $tester = new CommandTester(new PluginsInstallSelectedCommand(
            new InstallerPluginsSelection($this->selectionPath),
            $installer,
            $this->registry,
            $this->logger,
        ));
        $tester->execute([]);

        return $tester;
    }

    /**
     * @param list<mixed> $plugins
     */
    private function select(array $plugins): void
    {
        file_put_contents($this->selectionPath, json_encode(['plugins' => $plugins]));
    }

    /**
     * @return list<string>|null
     */
    private function selectionOnDisk(): ?array
    {
        return is_file($this->selectionPath) ? json_decode((string) file_get_contents($this->selectionPath), true)['plugins'] : null;
    }

    /**
     * @return array{installed: list<string>}
     */
    private function stdout(CommandTester $tester): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $tester->getDisplay()))));

        return json_decode((string) end($lines), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function finalRefusals(): iterable
    {
        $id = new PluginId('animedb-x');

        yield 'not in registry' => [new PluginNotInSnapshotException($id)];
        yield 'no compatible core' => [new IncompatibleCoreSnapshotException($id, '>=9.0.0')];
        yield 'no compatible contracts' => [new IncompatiblePluginContractsSnapshotException($id)];
        yield 'invalid installed plugin' => [new InvalidInstalledPluginException('/tmp/x', [new ManifestValidationError('id', 'bad manifest')])];
        yield 'cache warmup' => [new PluginCacheWarmupException('warmup failed')];
        yield 'other install failure' => [new PluginInstallException('boom')];
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function temporaryRefusals(): iterable
    {
        $id = new PluginId('animedb-x');

        yield 'no snapshot' => [new MarketSnapshotUnavailableException()];
        yield 'asset download' => [new PluginAssetDownloadException($id, '1.0.0', ['https://mirror.example/x' => 'offline'])];
        yield 'unknown version' => [new UnknownPluginVersionException($id, '1.0.0')];
    }

    #[DataProvider('finalRefusals')]
    public function testFinalRefusalDropsTheIdAndLogsIt(\Throwable $refusal): void
    {
        $this->select(['animedb-x']);

        $tester = $this->runCommand(static function () use ($refusal): never {
            throw $refusal;
        });

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertNull($this->selectionOnDisk());
        $this->assertSame(['installed' => []], $this->stdout($tester));
        $this->assertContains('warning', array_column($this->logger->logs, 'level'));
    }

    #[DataProvider('temporaryRefusals')]
    public function testTemporaryRefusalKeepsTheIdAndLeavesTheFileUntouched(\Throwable $refusal): void
    {
        $this->select(['animedb-x']);
        $before = file_get_contents($this->selectionPath);
        clearstatcache();
        $mtime = filemtime($this->selectionPath);

        $tester = $this->runCommand(static function () use ($refusal): never {
            throw $refusal;
        });

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame($before, file_get_contents($this->selectionPath));
        clearstatcache();
        $this->assertSame($mtime, filemtime($this->selectionPath));
        $this->assertSame(['installed' => []], $this->stdout($tester));
        $this->assertNotContains('warning', array_column($this->logger->logs, 'level'));
    }

    public function testIncompatibleVersionExceptionsAreFinalRefusals(): void
    {
        $this->select(['animedb-a', 'animedb-b']);
        $exceptions = [
            'animedb-a' => new IncompatiblePluginCoreVersionException('>=9.0.0', '2.0.0'),
            'animedb-b' => new IncompatiblePluginContractsVersionException('>=9.0.0', '2.0.0'),
        ];

        $tester = $this->runCommand(static function (PluginId $id) use ($exceptions): never {
            throw $exceptions[(string) $id];
        });

        $this->assertNull($this->selectionOnDisk());
        $this->assertSame(['installed' => []], $this->stdout($tester));
    }

    public function testInvalidIdIsDroppedAndLoggedWhileTheRestIsInstalled(): void
    {
        $this->select(['Not A Slug', 42, 'animedb-ok']);

        $tester = $this->runCommand(static fn (PluginId $id): PluginId => $id);

        $this->assertNull($this->selectionOnDisk());
        $this->assertSame(['installed' => ['animedb-ok']], $this->stdout($tester));
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertCount(2, array_filter($this->logger->logs, static fn (array $log): bool => $log['level'] === 'warning'));
    }

    public function testUnforeseenFailureOnLaterIdKeepsEarlierInstalledIdsInStdout(): void
    {
        $this->select(['animedb-a', 'animedb-b', 'animedb-c']);

        $tester = $this->runCommand(static function (PluginId $id): PluginId {
            if ((string) $id === 'animedb-b') {
                throw new \LogicException('boom');
            }

            return $id;
        });

        $this->assertSame(['installed' => ['animedb-a', 'animedb-c']], $this->stdout($tester));
        $this->assertSame(['animedb-b'], $this->selectionOnDisk());
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testAlreadyInstalledDuringTheRunIsDroppedAndNotReportedAsInstalled(): void
    {
        $this->select(['animedb-race', 'animedb-ok']);

        $tester = $this->runCommand(static function (PluginId $id): PluginId {
            if ((string) $id === 'animedb-race') {
                throw new PluginAlreadyInstalledException($id);
            }

            return $id;
        });

        $this->assertNull($this->selectionOnDisk());
        $this->assertSame(['installed' => ['animedb-ok']], $this->stdout($tester));
    }

    public function testAlreadyInstalledBeforeTheRunIsDroppedWithoutCallingTheInstaller(): void
    {
        $pluginDir = dirname($this->selectionPath).'/plugins/animedb-have';
        mkdir($pluginDir);
        file_put_contents($pluginDir.'/manifest.json', (string) json_encode([
            'id' => 'animedb-have', 'name' => 'Have', 'version' => '1.0.0', 'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
        $this->registry->reconcile();
        $this->select(['animedb-have']);
        $calls = 0;

        $tester = $this->runCommand(static function (PluginId $id) use (&$calls): PluginId {
            ++$calls;

            return $id;
        });

        $this->assertSame(0, $calls);
        $this->assertNull($this->selectionOnDisk());
        $this->assertSame(['installed' => []], $this->stdout($tester));
    }

    public function testMixedOutcomesKeepOnlyTheTemporarilyRefusedIds(): void
    {
        $this->select(['animedb-ok', 'animedb-offline', 'animedb-gone']);

        $tester = $this->runCommand(static function (PluginId $id): PluginId {
            return match ((string) $id) {
                'animedb-offline' => throw new UnknownPluginVersionException($id, '1.0.0'),
                'animedb-gone' => throw new PluginNotInSnapshotException($id),
                default => $id,
            };
        });

        $this->assertSame(['animedb-offline'], $this->selectionOnDisk());
        $this->assertSame(['installed' => ['animedb-ok']], $this->stdout($tester));
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testFileIsRewrittenAfterEveryIdBeforeTheNextOneIsProcessed(): void
    {
        $this->select(['animedb-a', 'animedb-b', 'animedb-c']);
        $seen = [];

        $this->runCommand(function (PluginId $id) use (&$seen): PluginId {
            $seen[(string) $id] = $this->selectionOnDisk();

            return $id;
        });

        $this->assertSame([
            'animedb-a' => ['animedb-a', 'animedb-b', 'animedb-c'],
            'animedb-b' => ['animedb-b', 'animedb-c'],
            'animedb-c' => ['animedb-c'],
        ], $seen);
        $this->assertNull($this->selectionOnDisk());
    }

    public function testInstallsWithoutPublishingTheWorkersReloadEvent(): void
    {
        $this->select(['animedb-a']);
        $flags = [];

        $this->runCommand(static function (PluginId $id, bool $update, bool $publish) use (&$flags): PluginId {
            $flags[] = [$update, $publish];

            return $id;
        });

        $this->assertSame([[false, false]], $flags);
    }

    public function testEmptyListRemovesTheFile(): void
    {
        $this->select([]);

        $tester = $this->runCommand(static fn (PluginId $id): PluginId => $id);

        $this->assertNull($this->selectionOnDisk());
        $this->assertSame(['installed' => []], $this->stdout($tester));
    }

    public function testMissingFileIsNotAnError(): void
    {
        $tester = $this->runCommand(static fn (PluginId $id): PluginId => $id);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame(['installed' => []], $this->stdout($tester));
    }

    public function testMalformedFileIsDiscardedAndLogged(): void
    {
        file_put_contents($this->selectionPath, '{not json');

        $tester = $this->runCommand(static fn (PluginId $id): PluginId => $id);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertNull($this->selectionOnDisk());
        $this->assertContains('error', array_column($this->logger->logs, 'level'));
    }

    public function testStdoutHoldsOnlyJsonLinesEvenWithRefusals(): void
    {
        $this->select(['animedb-a', 'animedb-b']);

        $tester = $this->runCommand(static function (PluginId $id): PluginId {
            if ((string) $id === 'animedb-a') {
                throw new PluginInstallException('boom');
            }

            return $id;
        });

        $this->assertSame(['installed' => ['animedb-b']], $this->stdout($tester));
        foreach (array_filter(array_map('trim', explode("\n", $tester->getDisplay()))) as $line) {
            $this->assertStringStartsWith('{"installed":', $line);
        }
    }

    public function testAccumulatedListIsPrintedAfterEveryInstalledId(): void
    {
        $this->select(['animedb-a', 'animedb-b']);

        $tester = $this->runCommand(static fn (PluginId $id): PluginId => $id);

        $this->assertSame(
            ['{"installed":["animedb-a"]}', '{"installed":["animedb-a","animedb-b"]}', '{"installed":["animedb-a","animedb-b"]}'],
            array_values(array_filter(array_map('trim', explode("\n", $tester->getDisplay())))),
        );
    }
}
