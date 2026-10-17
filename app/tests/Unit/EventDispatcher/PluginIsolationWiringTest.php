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

namespace App\Tests\Unit\EventDispatcher;

use App\Controller\AnimeNewController;
use App\Controller\AnimeSearchPluginsController;
use App\Controller\StorageScanConfirmController;
use App\EventDispatcher\PluginIsolatingEventDispatcher;
use App\MessageHandler\ScanStorageMessageHandler;
use App\Service\Download\DownloadCompletionPoller;
use App\Service\Storage\ManualLinkService;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\Debug\TraceableEventDispatcher;

/**
 * Every place that dispatches AnimeFilesChangedEvent / DownloadCompletedEvent gets the isolating
 * dispatcher from the container (issue #1075), not the bare one — so a failing plugin listener
 * cannot reach the caller from any of them.
 */
final class PluginIsolationWiringTest extends KernelTestCase
{
    /** @return iterable<string, array{class-string}> */
    public static function dispatchingServices(): iterable
    {
        yield 'AnimeNewController' => [AnimeNewController::class];
        yield 'AnimeSearchPluginsController' => [AnimeSearchPluginsController::class];
        yield 'StorageScanConfirmController' => [StorageScanConfirmController::class];
        yield 'DownloadCompletionPoller' => [DownloadCompletionPoller::class];
        yield 'ScanStorageMessageHandler' => [ScanStorageMessageHandler::class];
        yield 'ManualLinkService' => [ManualLinkService::class];
    }

    /** @param class-string $class */
    #[DataProvider('dispatchingServices')]
    public function testTheServiceDispatchesThroughTheIsolatingDispatcher(string $class): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $service = $container->get($class);
        $dispatcher = (new \ReflectionProperty($class, 'eventDispatcher'))->getValue($service);
        self::assertIsObject($dispatcher);

        // In debug the profiler's dispatcher wraps ours.
        if ($dispatcher instanceof TraceableEventDispatcher) {
            $dispatcher = (new \ReflectionProperty(TraceableEventDispatcher::class, 'dispatcher'))->getValue($dispatcher);
        }

        self::assertInstanceOf(PluginIsolatingEventDispatcher::class, $dispatcher);
    }
}
