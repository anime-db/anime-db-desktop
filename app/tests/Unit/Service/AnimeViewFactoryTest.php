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

namespace App\Tests\Unit\Service;

use App\Entity\Enum\AnimeType;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Entity\TvAnime;
use App\Service\AnimeViewFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class AnimeViewFactoryTest extends TestCase
{
    private function createViewFactory(string $locale): AnimeViewFactory
    {
        $request = new Request();
        $request->setLocale($locale);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new AnimeViewFactory($requestStack);
    }

    public function testSerializeResolvesSummaryForCurrentUiLocale(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('A Silent Voice')->setWatchStatus(WatchStatus::Plan);
        $anime->setDescription('ru', 'Описание')->setDescription('en', 'Description');

        $view = $this->createViewFactory('ru')->serialize($anime);

        $this->assertSame('Описание', $view['summary']);
    }

    public function testSerializeFallsBackToEnglishWhenUiLocaleHasNoDescription(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('A Silent Voice')->setWatchStatus(WatchStatus::Plan);
        $anime->setDescription('en', 'Description');

        $view = $this->createViewFactory('de')->serialize($anime);

        $this->assertSame('Description', $view['summary']);
    }

    public function testSerializeReturnsEmptySummaryWhenNoDescriptionsAreSet(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('A Silent Voice')->setWatchStatus(WatchStatus::Plan);

        $view = $this->createViewFactory('ru')->serialize($anime);

        $this->assertSame('', $view['summary']);
    }

    public function testSerializeFormatsPremiereAndEndDatesAsIsoDates(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('A Silent Voice')->setWatchStatus(WatchStatus::Plan);
        $anime->setDatePremiere(new \DateTimeImmutable('2009-04-05'))->setDateEnd(new \DateTimeImmutable('2010-07-04'));

        $view = $this->createViewFactory('en')->serialize($anime);

        $this->assertSame('2009-04-05', $view['date_premiere']);
        $this->assertSame('2010-07-04', $view['date_end']);
    }

    public function testSerializeReturnsNullDatesWhenNoneAreSet(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('A Silent Voice')->setWatchStatus(WatchStatus::Plan);

        $view = $this->createViewFactory('en')->serialize($anime);

        $this->assertNull($view['date_premiere']);
        $this->assertNull($view['date_end']);
    }

    public function testSerializeListsTypeChangesWithExactLostValuesForMovie(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Detective Conan')
            ->setDatePremiereAndEnd(new \DateTimeImmutable('1996-01-08'), new \DateTimeImmutable('2010-07-04'))
            ->setWatchStatus(WatchStatus::Watching);
        $anime->setEpisodesCount(1150);
        $anime->setWatchedEpisodes(1149);

        $changes = $this->createViewFactory('en')->serialize($anime)['type_changes'];

        $byType = [];
        foreach ($changes as $change) {
            $byType[$change['type']] = $change;
        }
        $this->assertArrayNotHasKey(AnimeType::Tv->value, $byType);
        $this->assertArrayHasKey(AnimeType::Movie->value, $byType);
        $this->assertSame([
            'type' => 'movie',
            'lossy' => true,
            'lost_episodes_count' => 1150,
            'lost_watched_episodes' => 1149,
            'lost_date_end' => '2010-07-04',
        ], $byType['movie']);
        foreach ($byType as $type => $change) {
            if ($type === 'movie') {
                continue;
            }
            $this->assertFalse($change['lossy'], $type);
            $this->assertNull($change['lost_episodes_count'], $type);
            $this->assertNull($change['lost_watched_episodes'], $type);
            $this->assertNull($change['lost_date_end'], $type);
        }
    }
}
