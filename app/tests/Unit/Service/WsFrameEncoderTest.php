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

namespace App\Tests\Unit\Service;

use App\Service\WsFrameEncoder;
use PHPUnit\Framework\TestCase;

final class WsFrameEncoderTest extends TestCase
{
    private WsFrameEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new WsFrameEncoder();
    }

    public function testEncodeShortPayload(): void
    {
        $this->assertSame("\x81\x05hello", $this->encoder->encode('hello'));
    }

    public function testEncodeEmptyPayload(): void
    {
        $this->assertSame("\x81\x00", $this->encoder->encode(''));
    }

    public function testEncode125BytePayload(): void
    {
        $payload = str_repeat('a', 125);

        $this->assertSame("\x81".chr(125).$payload, $this->encoder->encode($payload));
    }

    public function testEncode126BytePayloadUsesExtendedLength(): void
    {
        $payload = str_repeat('a', 126);

        $this->assertSame("\x81\x7e".pack('n', 126).$payload, $this->encoder->encode($payload));
    }

    public function testEncode65535BytePayload(): void
    {
        $payload = str_repeat('a', 0xFFFF);

        $this->assertSame("\x81\x7e".pack('n', 0xFFFF).$payload, $this->encoder->encode($payload));
    }

    public function testEncode65536BytePayloadUses8ByteLength(): void
    {
        $payload = str_repeat('a', 65536);

        $this->assertSame("\x81\x7f".pack('J', 65536).$payload, $this->encoder->encode($payload));
    }
}
