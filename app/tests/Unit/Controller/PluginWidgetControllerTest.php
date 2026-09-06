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

use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\PluginContracts\Widget\CatalogWidgetInterface;
use AnimeDb\PluginContracts\Widget\EntryWidgetInterface;
use App\Controller\PluginWidgetController;
use App\Entity\Anime;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Service\Plugin\CatalogWidgetRegistry;
use App\Service\Plugin\EntryWidgetRegistry;
use App\Service\Plugin\PluginHtmlSanitizer;
use App\Service\Plugin\PluginsConfigStore;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class PluginWidgetControllerTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir().'/anime-plugin-widget-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->configPath, $this->configPath.'.tmp', $this->configPath.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @param array<string, bool> $features
     */
    private function activeWidgets(string $pluginId, array $features): PluginsConfigStore
    {
        file_put_contents($this->configPath, json_encode([$pluginId => ['features' => $features]]));

        return new PluginsConfigStore($this->configPath);
    }

    private function createController(
        EntryWidgetRegistry $entryWidgets,
        CatalogWidgetRegistry $catalogWidgets,
        ?EntityManagerInterface $entityManager = null,
        ?Environment $twig = null,
        ?LoggerInterface $logger = null,
    ): PluginWidgetController {
        return new PluginWidgetController(
            $entryWidgets,
            $catalogWidgets,
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $twig ?? $this->createStub(Environment::class),
            $logger ?? $this->createStub(LoggerInterface::class),
            new PluginHtmlSanitizer(),
        );
    }

    private function emptyCatalogWidgets(): CatalogWidgetRegistry
    {
        return new CatalogWidgetRegistry([], new PluginsConfigStore(''), $this->createStub(TranslatorInterface::class));
    }

    private function emptyEntryWidgets(): EntryWidgetRegistry
    {
        return new EntryWidgetRegistry([], new PluginsConfigStore(''), $this->createStub(TranslatorInterface::class));
    }

    private function anime(): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Frieren')->setWatchStatus(WatchStatus::Watching)->addSource('https://shikimori.one/animes/52991');

        return $anime;
    }

    public function testRenderReturnsWidgetHtml(): void
    {
        $anime = $this->anime();

        $widget = $this->createMock(EntryWidgetInterface::class);
        $widget->expects($this->once())->method('render')->with(new AnimeId(5))->willReturn('<div>Related</div>');

        $entryWidgets = new EntryWidgetRegistry(
            ['animedb-shikimori:related' => $widget],
            $this->activeWidgets('animedb-shikimori', ['related' => true]),
            $this->createStub(TranslatorInterface::class),
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->with(Anime::class, 5)->willReturn($anime);
        $entityManager->expects($this->never())->method('flush');

        $controller = $this->createController($entryWidgets, $this->emptyCatalogWidgets(), entityManager: $entityManager);
        $response = $controller->render(
            'animedb-shikimori',
            'related',
            Request::create('/plugin/animedb-shikimori/widget/related', 'GET', ['entryId' => '5']),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('<div>Related</div>', $response->getContent());
        $this->assertTrue($response->headers->getCacheControlDirective('private'));
        $this->assertSame(300, $response->getMaxAge());
    }

    public function testRenderReturnsErrorFragmentAndLogsWhenWidgetThrows(): void
    {
        $anime = $this->anime();

        $widget = $this->createMock(EntryWidgetInterface::class);
        $widget->method('render')->willThrowException(new \RuntimeException('API unreachable'));

        $entryWidgets = new EntryWidgetRegistry(
            ['animedb-shikimori:related' => $widget],
            $this->activeWidgets('animedb-shikimori', ['related' => true]),
            $this->createStub(TranslatorInterface::class),
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn($anime);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('plugin/_widget_error.html.twig', $this->callback(
                static fn (array $params): bool => $params['pluginId'] === 'animedb-shikimori' && \is_string($params['retryUrl']),
            ))
            ->willReturn('<div>error</div>');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $controller = $this->createController($entryWidgets, $this->emptyCatalogWidgets(), entityManager: $entityManager, twig: $twig, logger: $logger);
        $response = $controller->render(
            'animedb-shikimori',
            'related',
            Request::create('/plugin/animedb-shikimori/widget/related', 'GET', ['entryId' => '5']),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('<div>error</div>', $response->getContent());
    }

    public function testRenderThrowsBadRequestWhenEntryIdIsMissing(): void
    {
        $widget = $this->createStub(EntryWidgetInterface::class);
        $entryWidgets = new EntryWidgetRegistry(
            ['animedb-shikimori:related' => $widget],
            $this->activeWidgets('animedb-shikimori', ['related' => true]),
            $this->createStub(TranslatorInterface::class),
        );

        $controller = $this->createController($entryWidgets, $this->emptyCatalogWidgets());

        $this->expectException(BadRequestHttpException::class);
        $controller->render('animedb-shikimori', 'related', Request::create('/plugin/animedb-shikimori/widget/related'));
    }

    public function testRenderThrowsNotFoundWhenAnimeDoesNotExist(): void
    {
        $widget = $this->createStub(EntryWidgetInterface::class);
        $entryWidgets = new EntryWidgetRegistry(
            ['animedb-shikimori:related' => $widget],
            $this->activeWidgets('animedb-shikimori', ['related' => true]),
            $this->createStub(TranslatorInterface::class),
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn(null);

        $controller = $this->createController($entryWidgets, $this->emptyCatalogWidgets(), entityManager: $entityManager);

        $this->expectException(NotFoundHttpException::class);
        $controller->render(
            'animedb-shikimori',
            'related',
            Request::create('/plugin/animedb-shikimori/widget/related', 'GET', ['entryId' => '999']),
        );
    }

    public function testRenderThrowsNotFoundWhenNoWidgetMatchesThePluginAndName(): void
    {
        $controller = $this->createController(
            $this->emptyEntryWidgets(),
            $this->emptyCatalogWidgets(),
        );

        $this->expectException(NotFoundHttpException::class);
        $controller->render('animedb-shikimori', 'related', Request::create('/plugin/animedb-shikimori/widget/related'));
    }

    public function testRenderThrowsNotFoundForAMalformedPluginId(): void
    {
        $controller = $this->createController(
            $this->emptyEntryWidgets(),
            $this->emptyCatalogWidgets(),
        );

        $this->expectException(NotFoundHttpException::class);
        $controller->render('Not A Valid Id', 'related', Request::create('/plugin/Not A Valid Id/widget/related'));
    }

    public function testRenderSanitizesScriptTagOutOfTheWidgetsOwnMarkup(): void
    {
        $widget = $this->createStub(CatalogWidgetInterface::class);
        $widget->method('render')->willReturn('<div>New releases</div><script>alert(1)</script>');

        $catalogWidgets = new CatalogWidgetRegistry(
            ['animedb-shikimori:new_releases' => $widget],
            $this->activeWidgets('animedb-shikimori', ['new_releases' => true]),
            $this->createStub(TranslatorInterface::class),
        );

        $controller = $this->createController($this->emptyEntryWidgets(), $catalogWidgets);

        $response = $controller->render(
            'animedb-shikimori',
            'new_releases',
            Request::create('/plugin/animedb-shikimori/widget/new_releases'),
        );

        $this->assertStringNotContainsString('<script', (string) $response->getContent());
        $this->assertStringContainsString('<div>New releases</div>', (string) $response->getContent());
    }

    public function testRenderCallsCatalogWidgetWithoutAnEntryIdOrDatabaseLookup(): void
    {
        $widget = $this->createMock(CatalogWidgetInterface::class);
        $widget->expects($this->once())->method('render')->with()->willReturn('<div>New releases</div>');

        $catalogWidgets = new CatalogWidgetRegistry(
            ['animedb-shikimori:new_releases' => $widget],
            $this->activeWidgets('animedb-shikimori', ['new_releases' => true]),
            $this->createStub(TranslatorInterface::class),
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('find');

        $controller = $this->createController(
            $this->emptyEntryWidgets(),
            $catalogWidgets,
            entityManager: $entityManager,
        );

        $response = $controller->render(
            'animedb-shikimori',
            'new_releases',
            Request::create('/plugin/animedb-shikimori/widget/new_releases'),
        );

        $this->assertSame('<div>New releases</div>', $response->getContent());
    }
}
