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

namespace App\Tests\Functional;

use App\Entity\Anime;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;

/**
 * Write-side (POST) endpoints through the real HTTP cycle: redirects after a successful POST,
 * a genuine 400 on an invalid CSRF token (no mocked token manager), 404 / 405 negatives.
 */
final class PostLayerTest extends FunctionalTestCase
{
    public function testSettingsLocaleSwitchWithValidTokenRedirectsBackToSettings(): void
    {
        $crawler = $this->client->request('GET', '/settings');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('form[action="/settings"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/settings', ['_token' => $token, 'locale' => 'en']);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/settings');
    }

    public function testSettingsLocaleSwitchWithInvalidTokenIsBadRequest(): void
    {
        $this->client->request('POST', '/settings', ['_token' => 'forged', 'locale' => 'en']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testSettingsLocaleSwitchWithoutTokenIsBadRequest(): void
    {
        $this->client->request('POST', '/settings', ['locale' => 'en']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testSettingsLocaleSwitchWithUnknownLocaleIsBadRequest(): void
    {
        $crawler = $this->client->request('GET', '/settings');
        $token = $crawler->filter('form[action="/settings"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/settings', ['_token' => $token, 'locale' => 'xx-unknown']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testAnimeDeleteWithValidTokenRemovesTheEntryAndRedirects(): void
    {
        $id = $this->createAnime();
        $crawler = $this->client->request('GET', '/anime/'.$id);
        self::assertResponseIsSuccessful();

        $token = $crawler->filter(\sprintf('form[action="/anime/%d/delete"] input[name="_token"]', $id))->first()->attr('value');

        $this->client->request('POST', '/anime/'.$id.'/delete', ['_token' => $token]);

        self::assertResponseStatusCodeSame(302);
        self::assertNull($this->entityManager()->find(Anime::class, $id));
    }

    public function testAnimeDeleteWithInvalidTokenIsBadRequestAndKeepsTheEntry(): void
    {
        $id = $this->createAnime();

        $this->client->request('POST', '/anime/'.$id.'/delete', ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(400);
        self::assertNotNull($this->entityManager()->find(Anime::class, $id));
    }

    public function testAnimeDeleteOfMissingEntryIsNotFound(): void
    {
        $this->client->request('POST', '/anime/999999/delete', ['_token' => 'whatever']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testPostToGetOnlyRouteIsMethodNotAllowed(): void
    {
        $this->client->request('POST', '/health');

        self::assertResponseStatusCodeSame(405);
    }

    public function testUnknownRouteIsNotFound(): void
    {
        $this->client->request('GET', '/no-such-page');

        self::assertResponseStatusCodeSame(404);
    }

    private function createAnime(): int
    {
        $anime = (new MovieAnime())->setTitle('Functional test entry')->setWatchStatus(WatchStatus::Plan);
        $entityManager = $this->entityManager();
        $entityManager->persist($anime);
        $entityManager->flush();

        return $anime->id ?? throw new \LogicException('Anime was not persisted.');
    }
}
