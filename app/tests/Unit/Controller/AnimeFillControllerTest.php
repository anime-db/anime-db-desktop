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

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use App\Controller\AnimeFillController;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Repository\StudioRepository;
use App\Service\AnimeViewFactory;
use App\Service\Plugin\Filler\FieldFillerService;
use App\Service\Plugin\Filler\FillableFieldsPresenter;
use App\Service\Plugin\Filler\PluginAnimeDataMerger;
use App\Service\Plugin\Filler\PluginMediaDownloaderInterface;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Exercises the full "click the fill button" path (issue #234): a real FieldFillerService and
 * FillableFieldsPresenter, backed by an in-memory sqlite EntityManager, so the resolve/merge
 * chain actually runs - only the HTTP-facing Twig::render() call is mocked, the same
 * direct-instantiation style AnimeEditableControllerTest (issue #103) already uses, since this
 * codebase has no WebTestCase-based HTTP client test anywhere.
 */
final class AnimeFillControllerTest extends TestCase
{
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 3).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
    }

    private function persistedAnime(): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Bleach')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    /** @param iterable<string, FillerInterface> $fillers */
    private function createController(
        iterable $fillers,
        ?Environment $twig = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?PluginMediaDownloaderInterface $mediaDownloader = null,
    ): AnimeFillController {
        $pluginsConfigPath = sys_get_temp_dir().'/anime-fill-controller-test-'.uniqid().'.json';
        $registry = new FillerRegistry($fillers, new PluginsConfigStore($pluginsConfigPath));

        $fieldFiller = new FieldFillerService(
            $registry,
            new PluginAnimeDataMerger(
                new StudioRepository($this->entityManager),
                $this->entityManager,
                $mediaDownloader ?? $this->createStub(PluginMediaDownloaderInterface::class),
            ),
            $this->entityManager,
            new ArrayAdapter(),
            new NullLogger(),
        );

        $fillableFieldsPresenter = new FillableFieldsPresenter(
            $registry,
            new InstalledPluginsRegistry(sys_get_temp_dir(), new PluginsConfigStore($pluginsConfigPath), new NullLogger()),
        );

        if ($csrfTokenManager === null) {
            $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
            $csrfTokenManager->method('isTokenValid')->willReturn(true);
        }

        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        return new AnimeFillController(
            $fieldFiller,
            $fillableFieldsPresenter,
            $csrfTokenManager,
            new AnimeViewFactory($requestStack),
            $twig ?? $this->createStub(Environment::class),
        );
    }

    public function testFillAppliesTheFieldWhenOnlyOnePluginSupportsItAndRendersTheFragment(): void
    {
        $data = new PluginAnimeData(title: 'Bleach', durationMinutes: 24);
        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['durationMinutes']);
        $filler->method('resolveExternalId')->willReturn('104');
        $filler->method('findById')->willReturn($data);

        $anime = $this->persistedAnime();

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/_fill_fields.html.twig', $this->callback(
                static fn (array $params): bool => $params['fill_error'] === null && $params['anime']['duration_minutes'] === 24,
            ))
            ->willReturn('<div></div>');

        $controller = $this->createController(['animedb-shikimori' => $filler], $twig);

        $request = Request::create('/anime/1/fill/durationMinutes', 'POST', ['plugin_id' => 'animedb-shikimori', '_token' => 'token']);

        $response = $controller->fill($anime, 'durationMinutes', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(24, $anime->getDurationMinutes());
    }

    public function testFillWithMultipleActivePluginsUsesTheExplicitlyChosenOne(): void
    {
        $data = new PluginAnimeData(title: 'Bleach', durationMinutes: 24);

        $shikimori = $this->createStub(FillerInterface::class);
        $shikimori->method('getFillableFields')->willReturn(['durationMinutes']);

        $anilist = $this->createMock(FillerInterface::class);
        $anilist->method('getFillableFields')->willReturn(['durationMinutes']);
        $anilist->method('resolveExternalId')->willReturn('204');
        $anilist->expects($this->once())->method('findById')->with('204')->willReturn($data);

        $anime = $this->persistedAnime();

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<div></div>');

        $controller = $this->createController(['animedb-shikimori' => $shikimori, 'animedb-anilist' => $anilist], $twig);

        $request = Request::create('/anime/1/fill/durationMinutes', 'POST', ['plugin_id' => 'animedb-anilist', '_token' => 'token']);

        $controller->fill($anime, 'durationMinutes', $request);

        $this->assertSame(24, $anime->getDurationMinutes());
    }

    public function testFillLeavesTheFieldUnchangedAndReturnsAnInlineNoticeWhenNothingMatches(): void
    {
        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['durationMinutes']);
        $filler->method('resolveExternalId')->willReturn(null);
        $filler->method('find')->willReturn([]);

        $anime = $this->persistedAnime();

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/_fill_fields.html.twig', $this->callback(
                static fn (array $params): bool => $params['fill_error'] === 'anime_detail.error_fill_not_found'
                    && $params['anime']['duration_minutes'] === null,
            ))
            ->willReturn('<div></div>');

        $controller = $this->createController(['animedb-shikimori' => $filler], $twig);

        $request = Request::create('/anime/1/fill/durationMinutes', 'POST', ['plugin_id' => 'animedb-shikimori', '_token' => 'token']);

        $response = $controller->fill($anime, 'durationMinutes', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($anime->getDurationMinutes());
    }

    public function testFillingCoverSuccessfullyAlsoRendersTheMediaPartialAsAnOobSwap(): void
    {
        $data = new PluginAnimeData(title: 'Bleach', cover: 'https://example.test/cover.jpg');
        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['cover']);
        $filler->method('resolveExternalId')->willReturn('104');
        $filler->method('findById')->willReturn($data);

        $downloader = $this->createStub(PluginMediaDownloaderInterface::class);
        $downloader->method('download')->willReturn('abc123.jpg');

        $anime = $this->persistedAnime();

        $rendered = [];
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->exactly(2))
            ->method('render')
            ->willReturnCallback(function (string $template, array $params) use (&$rendered): string {
                $rendered[] = [$template, $params];

                return '<div data-template="'.$template.'"></div>';
            });

        $controller = $this->createController(['animedb-shikimori' => $filler], $twig, mediaDownloader: $downloader);

        $request = Request::create('/anime/1/fill/cover', 'POST', ['plugin_id' => 'animedb-shikimori', '_token' => 'token']);

        $response = $controller->fill($anime, 'cover', $request);

        $this->assertSame('abc123.jpg', $anime->getCover());
        $this->assertSame('anime/_fill_fields.html.twig', $rendered[0][0]);
        $this->assertNull($rendered[0][1]['fill_error']);
        $this->assertSame('anime/_media.html.twig', $rendered[1][0]);
        $this->assertSame('abc123.jpg', $rendered[1][1]['anime']['cover']);
        $content = (string) $response->getContent();
        $this->assertStringContainsString('anime/_fill_fields.html.twig', $content);
        $this->assertStringContainsString('anime/_media.html.twig', $content);
    }

    public function testFillingImagesSuccessfullyAlsoRendersTheGalleryPartialAsAnOobSwap(): void
    {
        $data = new PluginAnimeData(title: 'Bleach', images: ['https://example.test/1.jpg']);
        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['images']);
        $filler->method('resolveExternalId')->willReturn('104');
        $filler->method('findById')->willReturn($data);

        $downloader = $this->createStub(PluginMediaDownloaderInterface::class);
        $downloader->method('download')->willReturn('new.jpg');

        $anime = $this->persistedAnime();

        $rendered = [];
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->exactly(2))
            ->method('render')
            ->willReturnCallback(function (string $template, array $params) use (&$rendered): string {
                $rendered[] = [$template, $params];

                return '<div data-template="'.$template.'"></div>';
            });

        $controller = $this->createController(['animedb-shikimori' => $filler], $twig, mediaDownloader: $downloader);

        $request = Request::create('/anime/1/fill/images', 'POST', ['plugin_id' => 'animedb-shikimori', '_token' => 'token']);

        $controller->fill($anime, 'images', $request);

        $this->assertSame('anime/_fill_fields.html.twig', $rendered[0][0]);
        $this->assertSame('anime/_gallery.html.twig', $rendered[1][0]);
        $this->assertSame(['new.jpg'], $rendered[1][1]['anime']['images']);
    }

    public function testFillingCoverWithAnUndownloadableUrlRendersTheImageRejectedErrorAndNoOobPartial(): void
    {
        $data = new PluginAnimeData(title: 'Bleach', cover: 'https://example.test/broken.jpg');
        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['cover']);
        $filler->method('resolveExternalId')->willReturn('104');
        $filler->method('findById')->willReturn($data);

        $downloader = $this->createStub(PluginMediaDownloaderInterface::class);
        $downloader->method('download')->willReturn(null);

        $anime = $this->persistedAnime();

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/_fill_fields.html.twig', $this->callback(
                static fn (array $params): bool => $params['fill_error'] === 'anime_detail.error_fill_image_rejected',
            ))
            ->willReturn('<div></div>');

        $controller = $this->createController(['animedb-shikimori' => $filler], $twig, mediaDownloader: $downloader);

        $request = Request::create('/anime/1/fill/cover', 'POST', ['plugin_id' => 'animedb-shikimori', '_token' => 'token']);

        $controller->fill($anime, 'cover', $request);

        $this->assertNull($anime->getCover());
    }

    public function testFillRejectsAnInvalidCsrfToken(): void
    {
        $anime = $this->persistedAnime();

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = $this->createController([], null, $csrf);

        $request = Request::create('/anime/1/fill/durationMinutes', 'POST', ['plugin_id' => 'animedb-shikimori', '_token' => 'invalid']);

        $this->expectException(BadRequestHttpException::class);

        $controller->fill($anime, 'durationMinutes', $request);
    }
}
