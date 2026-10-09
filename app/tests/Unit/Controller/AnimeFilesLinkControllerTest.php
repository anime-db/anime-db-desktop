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

use App\Controller\AnimeFilesLinkController;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Repository\AnimeRepository;
use App\Repository\StorageRepository;
use App\Service\AnimeViewFactory;
use App\Service\Storage\ManualLinkService;
use App\Service\Storage\StorageMarkerService;
use App\Tests\Support\BuildsAnimeDeleteService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/** The manual link/unlink routes of the entry card's Files block (issue #997). */
final class AnimeFilesLinkControllerTest extends TestCase
{
    use BuildsAnimeDeleteService;

    private EntityManager $entityManager;

    /** @var array<string, mixed> */
    private array $rendered = [];

    /** @var list<string> */
    private array $dirsToClean = [];

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
        $this->entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        foreach ($this->dirsToClean as $dir) {
            $this->removeDir($dir);
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function makeDir(): string
    {
        $dir = sys_get_temp_dir().'/anime-files-link-test-'.uniqid();
        mkdir($dir, recursive: true);
        $this->dirsToClean[] = $dir;

        return $dir;
    }

    private function createController(bool $validCsrf = true): AnimeFilesLinkController
    {
        $markers = new StorageMarkerService($this->entityManager);
        $service = new ManualLinkService(
            new StorageRepository($this->entityManager),
            new AnimeRepository($this->entityManager),
            $markers,
            $this->newJobLockService(),
            $this->entityManager,
            new EventDispatcher(),
            new NullLogger(),
        );

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn($validCsrf);

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            static fn (string $name, array $params = []): string => '/'.$name.'?'.http_build_query($params),
        );

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $template, array $params): string {
            $this->rendered = ['template' => $template] + $params;

            return '<section id="anime-files"></section>';
        });

        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        return new AnimeFilesLinkController($service, $csrf, $urls, new AnimeViewFactory($requestStack), $twig);
    }

    private function newStorage(string $path): Storage
    {
        $storage = new Storage('Local', $path, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        return $storage;
    }

    private function newAnime(string $title = 'Trigun'): Anime
    {
        $anime = new TvAnime();
        $anime->setTitle($title)->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    /** @param array<string, string|int> $fields */
    private function post(array $fields = []): Request
    {
        return Request::create('/anime/1/link-files', 'POST', $fields + ['_token' => 'token']);
    }

    public function testLinkBindsTheEntryAndRendersTheFilesFragmentWithTheTopLevelNotice(): void
    {
        $root = $this->makeDir();
        mkdir($root.'/Trigun/Season 1', recursive: true);
        $storage = $this->newStorage($root);
        $anime = $this->newAnime();

        $response = $this->createController()->link($anime, $this->post(['path' => $root.'/Trigun/Season 1']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('anime/_files.html.twig', $this->rendered['template']);
        $this->assertSame('anime_detail.files_linked_top_level', $this->rendered['files_message']['key']);
        $this->assertSame('Trigun', $this->rendered['files_message']['params']['%name%']);
        $this->assertSame($storage, $anime->getStorage());
        $this->assertSame('Trigun', $anime->getStoragePath());
    }

    public function testAPathOutsideEveryStorageRendersARefusalWithTheCreateStorageLinkPrefilledWithTheParent(): void
    {
        $this->newStorage($this->makeDir());
        $other = $this->makeDir();
        mkdir($other.'/Trigun');

        $this->createController()->link($this->newAnime(), $this->post(['path' => $other.'/Trigun']));

        $message = $this->rendered['files_message'];
        $this->assertSame('error', $message['kind']);
        $this->assertSame('anime_detail.files_error_outside_storages', $message['key']);
        $this->assertSame('/storage_new?'.http_build_query(['path' => $other]), $message['link_url']);
    }

    public function testAnOccupiedPairRendersARefusalLinkingToTheHolder(): void
    {
        $root = $this->makeDir();
        mkdir($root.'/Trigun');
        $storage = $this->newStorage($root);
        $holder = $this->newAnime('Holder');
        $holder->setStorage($storage)->setStoragePath('Trigun');
        $this->entityManager->flush();

        $this->createController()->link($this->newAnime(), $this->post(['path' => $root.'/Trigun']));

        $message = $this->rendered['files_message'];
        $this->assertSame('anime_detail.files_error_occupied', $message['key']);
        $this->assertSame('/anime_show?id='.$holder->id, $message['link_url']);
        $this->assertSame('Holder', $message['link_text']);
    }

    public function testAMovedStorageAnswers409WithTheRelocationAndThenLinksOnConfirmation(): void
    {
        $oldRoot = $this->makeDir();
        $newRoot = $this->makeDir();
        mkdir($newRoot.'/Trigun');
        $storage = $this->newStorage($oldRoot);
        file_put_contents($newRoot.'/desktop.ini', \sprintf("[AnimeDB]\nid=%d\n", $storage->id));
        $anime = $this->newAnime();
        $controller = $this->createController();

        $response = $controller->link($anime, $this->post(['path' => $newRoot.'/Trigun']));

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(
            ['relocate' => ['storage_id' => $storage->id, 'name' => 'Local', 'old_path' => $oldRoot, 'new_path' => $newRoot]],
            json_decode((string) $response->getContent(), true),
        );
        $this->assertNull($anime->getStorage());

        $response = $controller->link($anime, $this->post(['path' => $newRoot.'/Trigun', 'relocate_storage_id' => (string) $storage->id]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($newRoot, $storage->getPath());
        $this->assertSame('Trigun', $anime->getStoragePath());
    }

    public function testUnlinkClearsTheLinkAndRendersTheFragment(): void
    {
        $storage = $this->newStorage($this->makeDir());
        $anime = $this->newAnime();
        $anime->setStorage($storage)->setStoragePath('Trigun');
        $this->entityManager->flush();

        $response = $this->createController()->unlink($anime, $this->post());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('anime_detail.files_unlinked', $this->rendered['files_message']['key']);
        $this->assertNull($anime->getStorage());
        $this->assertNull($anime->getStoragePath());
    }

    public function testBothRoutesRejectAnInvalidCsrfToken(): void
    {
        $anime = $this->newAnime();
        $controller = $this->createController(validCsrf: false);

        foreach ([
            static fn () => $controller->link($anime, Request::create('/', 'POST', ['path' => '/x'])),
            static fn () => $controller->unlink($anime, Request::create('/', 'POST')),
        ] as $call) {
            try {
                $call();
                $this->fail('A BadRequestHttpException was expected.');
            } catch (BadRequestHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
