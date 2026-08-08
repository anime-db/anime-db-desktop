<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Service\Sync\PullPushSuppressor;
use PHPUnit\Framework\TestCase;

final class PullPushSuppressorTest extends TestCase
{
    public function testIsNotSuppressedByDefault(): void
    {
        $suppressor = new PullPushSuppressor();

        $this->assertFalse($suppressor->isSuppressed());
    }

    public function testIsSuppressedOnlyWhileTheCallbackRuns(): void
    {
        $suppressor = new PullPushSuppressor();

        $observedDuring = null;
        $suppressor->suppress(function () use ($suppressor, &$observedDuring): void {
            $observedDuring = $suppressor->isSuppressed();
        });

        $this->assertTrue($observedDuring);
        $this->assertFalse($suppressor->isSuppressed());
    }

    public function testReturnsTheCallbacksResult(): void
    {
        $suppressor = new PullPushSuppressor();

        $result = $suppressor->suppress(static fn (): string => 'value');

        $this->assertSame('value', $result);
    }

    public function testStaysSuppressedUntilTheOutermostNestedCallReturns(): void
    {
        $suppressor = new PullPushSuppressor();

        $suppressor->suppress(function () use ($suppressor): void {
            $suppressor->suppress(function () use ($suppressor): void {
                $this->assertTrue($suppressor->isSuppressed());
            });

            // Still inside the outer scope after the nested call finished.
            $this->assertTrue($suppressor->isSuppressed());
        });

        $this->assertFalse($suppressor->isSuppressed());
    }

    public function testClearsSuppressionEvenWhenTheCallbackThrows(): void
    {
        $suppressor = new PullPushSuppressor();

        $this->expectException(\RuntimeException::class);
        try {
            $suppressor->suppress(static function (): void {
                throw new \RuntimeException('boom');
            });
        } finally {
            $this->assertFalse($suppressor->isSuppressed());
        }
    }
}
