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

namespace App\Tests\Unit\Controller\Settings;

use App\Controller\Settings\LabelController;
use App\Entity\Label;
use App\Entity\TvAnime;
use App\Repository\LabelRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class LabelControllerTest extends TestCase
{
    private function createController(
        ?LabelRepository $labels = null,
        ?EntityManagerInterface $entityManager = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?UrlGeneratorInterface $urlGenerator = null,
        ?Environment $twig = null,
    ): LabelController {
        if ($csrfTokenManager === null) {
            $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
            $csrfTokenManager->method('isTokenValid')->willReturn(true);
        }

        if ($urlGenerator === null) {
            $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
            $urlGenerator->method('generate')->willReturn('/settings/labels');
        }

        return new LabelController(
            $labels ?? $this->createStub(LabelRepository::class),
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $csrfTokenManager,
            $urlGenerator,
            $twig ?? $this->createStub(Environment::class),
        );
    }

    public function testIndexPassesLabelsToTemplate(): void
    {
        $label = new Label();
        $label->rename('favorite');

        $labels = $this->createStub(LabelRepository::class);
        $labels->method('findAllOrderedByName')->willReturn([$label]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/label/index.html.twig', $this->callback(
                static fn (array $params): bool => [$label] === $params['labels'],
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(labels: $labels, twig: $twig);
        $response = $controller->index(Request::create('/settings/labels'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRenameUpdatesLabelName(): void
    {
        $label = new Label();
        $label->rename('old-name');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $controller = $this->createController(entityManager: $entityManager);
        $request = Request::create('/settings/labels/1/rename', 'POST', ['name' => 'new-name', '_token' => 'token']);

        $controller->rename($label, $request);

        $this->assertSame('new-name', $label->name);
    }

    public function testRenameWithEmptyNameDoesNotFlushAndRedirectsWithError(): void
    {
        $label = new Label();
        $label->rename('old-name');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects($this->once())
            ->method('generate')
            ->with('settings_labels_index', ['error' => 'empty_name'])
            ->willReturn('/settings/labels?error=empty_name');

        $controller = $this->createController(entityManager: $entityManager, urlGenerator: $router);
        $request = Request::create('/settings/labels/1/rename', 'POST', ['name' => '   ', '_token' => 'token']);

        $response = $controller->rename($label, $request);

        $this->assertSame('old-name', $label->name);
        $this->assertSame('/settings/labels?error=empty_name', $response->getTargetUrl());
    }

    public function testRenameRejectsInvalidCsrfToken(): void
    {
        $label = new Label();
        $label->rename('old-name');

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = $this->createController(csrfTokenManager: $csrf);
        $request = Request::create('/settings/labels/1/rename', 'POST', ['name' => 'new-name', '_token' => 'bad']);

        $this->expectException(BadRequestHttpException::class);
        $controller->rename($label, $request);
    }

    public function testDeleteDetachesLabelFromAllLinkedAnime(): void
    {
        $label = new Label();
        $label->rename('favorite');

        $anime1 = new TvAnime();
        $anime1->addLabel($label);
        $anime2 = new TvAnime();
        $anime2->addLabel($label);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('remove')->with($label);
        $entityManager->expects($this->once())->method('flush');

        $controller = $this->createController(entityManager: $entityManager);
        $request = Request::create('/settings/labels/1/delete', 'POST', ['_token' => 'token']);

        $controller->delete($label, $request);

        $this->assertCount(0, $label->getAnimes());
        $this->assertFalse($anime1->getLabels()->contains($label));
        $this->assertFalse($anime2->getLabels()->contains($label));
    }

    public function testDeleteRejectsInvalidCsrfToken(): void
    {
        $label = new Label();
        $label->rename('favorite');

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');

        $controller = $this->createController(entityManager: $entityManager, csrfTokenManager: $csrf);
        $request = Request::create('/settings/labels/1/delete', 'POST', ['_token' => 'bad']);

        $this->expectException(BadRequestHttpException::class);
        $controller->delete($label, $request);
    }
}
