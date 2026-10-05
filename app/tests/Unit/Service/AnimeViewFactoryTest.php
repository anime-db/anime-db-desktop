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

use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
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
}
