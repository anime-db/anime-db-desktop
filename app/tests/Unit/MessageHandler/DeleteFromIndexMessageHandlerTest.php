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

namespace App\Tests\Unit\MessageHandler;

use App\Message\DeleteFromIndexMessage;
use App\MessageHandler\DeleteFromIndexMessageHandler;
use App\Service\Search\AnimeSearchIndexer;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use PHPUnit\Framework\TestCase;

/**
 * Same doubling strategy as IndexAnimeMessageHandlerTest: AnimeSearchIndexer is final, so the
 * Meilisearch\Client it wraps is what gets doubled here.
 */
final class DeleteFromIndexMessageHandlerTest extends TestCase
{
    public function testForwardsTheAnimeIdToTheIndexer(): void
    {
        $index = $this->createMock(Indexes::class);
        $index->expects($this->once())->method('deleteDocument')->with(42)->willReturn(['taskUid' => 7]);
        $index->expects($this->once())->method('waitForTask')->with(7);

        $client = $this->createMock(Client::class);
        $client->method('index')->with('anime')->willReturn($index);

        $handler = new DeleteFromIndexMessageHandler(new AnimeSearchIndexer($client));
        $handler(new DeleteFromIndexMessage(42));
    }
}
