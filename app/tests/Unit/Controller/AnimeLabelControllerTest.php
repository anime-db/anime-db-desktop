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

namespace App\Tests\Unit\Controller;

use App\Controller\AnimeLabelController;
use App\Entity\Label;
use App\Entity\TvAnime;
use App\Repository\LabelRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AnimeLabelControllerTest extends TestCase
{
    private function createController(
        ?LabelRepository $labels = null,
        ?EntityManagerInterface $entityManager = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ): AnimeLabelController {
        if ($csrfTokenManager === null) {
            $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
            $csrfTokenManager->method('isTokenValid')->willReturn(true);
        }

        return new AnimeLabelController(
            $labels ?? $this->createStub(LabelRepository::class),
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $csrfTokenManager,
        );
    }

    private function jsonRequest(mixed $payload): Request
    {
        return Request::create('/anime/1/labels', 'POST', [], [], [], [], json_encode($payload) ?: '');
    }

    public function testUpdateReusesExistingLabelAndCreatesNewOne(): void
    {
        $existing = new Label('favorite');

        $labels = $this->createStub(LabelRepository::class);
        $labels->method('findOneByName')->willReturnMap([
            ['favorite', $existing],
            ['new label', null],
        ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist')->with($this->isInstanceOf(Label::class));
        $entityManager->expects($this->once())->method('flush');

        $anime = new TvAnime();

        $controller = $this->createController(labels: $labels, entityManager: $entityManager);
        $response = $controller->update($anime, $this->jsonRequest(['token' => 'token', 'names' => ['favorite', 'new label']]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($anime->getLabels()->contains($existing));

        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame(['favorite', 'new label'], array_column($body['labels'], 'name'));
    }

    public function testUpdateRemovesLabelsNoLongerSubmitted(): void
    {
        $kept = new Label('kept');
        $removed = new Label('removed');

        $anime = new TvAnime();
        $anime->addLabel($kept);
        $anime->addLabel($removed);

        $labels = $this->createStub(LabelRepository::class);
        $labels->method('findOneByName')->willReturnMap([['kept', $kept]]);

        $controller = $this->createController(labels: $labels);
        $controller->update($anime, $this->jsonRequest(['token' => 'token', 'names' => ['kept']]));

        $this->assertTrue($anime->getLabels()->contains($kept));
        $this->assertFalse($anime->getLabels()->contains($removed));
    }

    public function testUpdateTrimsAndDeduplicatesNames(): void
    {
        $favorite = new Label('favorite');

        $labels = $this->createStub(LabelRepository::class);
        $labels->method('findOneByName')->willReturnMap([['favorite', $favorite]]);

        $anime = new TvAnime();

        $controller = $this->createController(labels: $labels);
        $response = $controller->update($anime, $this->jsonRequest(['token' => 'token', 'names' => [' favorite ', 'favorite', '  ']]));

        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame([['id' => null, 'name' => 'favorite']], $body['labels']);
    }

    public function testUpdateRejectsInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = $this->createController(csrfTokenManager: $csrf);

        $this->expectException(BadRequestHttpException::class);
        $controller->update(new TvAnime(), $this->jsonRequest(['token' => 'bad', 'names' => []]));
    }

    public function testUpdateRejectsNonArrayNames(): void
    {
        $controller = $this->createController();

        $this->expectException(BadRequestHttpException::class);
        $controller->update(new TvAnime(), $this->jsonRequest(['token' => 'token', 'names' => 'favorite']));
    }

    public function testUpdateRejectsNonStringNameInList(): void
    {
        $controller = $this->createController();

        $this->expectException(BadRequestHttpException::class);
        $controller->update(new TvAnime(), $this->jsonRequest(['token' => 'token', 'names' => [42]]));
    }
}
