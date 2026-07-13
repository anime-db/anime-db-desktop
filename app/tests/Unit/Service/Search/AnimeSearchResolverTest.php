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

namespace App\Tests\Unit\Service\Search;

use App\Service\Search\AnimeSearchResolver;
use Meilisearch\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

/**
 * Exercises AnimeSearchResolver against a fake PSR-18 HTTP client instead of a real
 * Meilisearch process: this is the only way to deterministically reproduce a connection
 * failure (issue #199's "Meilisearch unavailable" fallback criterion) without depending on
 * network timing. The success case still goes through the real Meilisearch\Client/Indexes
 * machinery, only the transport is faked.
 */
final class AnimeSearchResolverTest extends TestCase
{
    public function testResolvesIdsFromASuccessfulSearchResponse(): void
    {
        $httpClient = new FakeHttpClient(fn (): Response => new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'hits' => [['id' => 3], ['id' => 7]],
                'offset' => 0,
                'limit' => 10_000,
                'estimatedTotalHits' => 2,
                'processingTimeMs' => 1,
                'query' => 'trigun',
            ], \JSON_THROW_ON_ERROR),
        ));

        $resolver = new AnimeSearchResolver($this->createClient($httpClient), new NullLogger());

        $this->assertSame([3, 7], $resolver->tryResolveIds('trigun'));
    }

    public function testReturnsAnEmptyListWhenMeilisearchGenuinelyFindsNothing(): void
    {
        $httpClient = new FakeHttpClient(fn (): Response => new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'hits' => [],
                'offset' => 0,
                'limit' => 10_000,
                'estimatedTotalHits' => 0,
                'processingTimeMs' => 1,
                'query' => 'no such anime',
            ], \JSON_THROW_ON_ERROR),
        ));

        $resolver = new AnimeSearchResolver($this->createClient($httpClient), new NullLogger());

        $this->assertSame([], $resolver->tryResolveIds('no such anime'));
    }

    public function testReturnsNullInsteadOfThrowingWhenMeilisearchIsUnreachable(): void
    {
        $httpClient = new FakeHttpClient(function (): never {
            throw new class('connection refused') extends \RuntimeException implements NetworkExceptionInterface {
                public function getRequest(): RequestInterface
                {
                    return (new Psr17Factory())->createRequest('POST', 'http://127.0.0.1:1/indexes/anime/search');
                }
            };
        });

        $resolver = new AnimeSearchResolver($this->createClient($httpClient), new NullLogger());

        $this->assertNull($resolver->tryResolveIds('trigun'));
    }

    private function createClient(ClientInterface $httpClient): Client
    {
        $factory = new Psr17Factory();

        return new Client('http://127.0.0.1:1', null, $httpClient, $factory, [], $factory);
    }
}

/**
 * @internal
 */
final class FakeHttpClient implements ClientInterface
{
    public function __construct(private readonly \Closure $handler)
    {
    }

    public function sendRequest(RequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        return ($this->handler)($request);
    }
}
