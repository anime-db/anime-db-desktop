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

namespace App\Tests\Unit\Controller;

use App\Controller\LabelController;
use App\Entity\Label;
use App\Repository\LabelRepository;
use PHPUnit\Framework\TestCase;

final class LabelControllerTest extends TestCase
{
    public function testIndexReturnsAllLabelsAsJson(): void
    {
        $favorite = new Label('favorite');
        $rewatch = new Label('rewatch');

        $labels = $this->createStub(LabelRepository::class);
        $labels->method('findAllOrderedByName')->willReturn([$favorite, $rewatch]);

        $controller = new LabelController($labels);
        $response = $controller->index();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['labels' => [['id' => null, 'name' => 'favorite'], ['id' => null, 'name' => 'rewatch']]],
            json_decode((string) $response->getContent(), true),
        );
    }

    public function testIndexReturnsEmptyListWhenNoLabelsExist(): void
    {
        $labels = $this->createStub(LabelRepository::class);
        $labels->method('findAllOrderedByName')->willReturn([]);

        $controller = new LabelController($labels);
        $response = $controller->index();

        $this->assertSame(['labels' => []], json_decode((string) $response->getContent(), true));
    }
}
