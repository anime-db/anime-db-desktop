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

namespace App\Tests\Unit\Controller\Settings;

use App\Controller\Settings\DescriptionSourcesController;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Plugin\Filler\DescriptionSourceCandidates;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class DescriptionSourcesControllerTest extends TestCase
{
    private string $configPath;
    /** @var array<string, mixed> */
    private array $rendered = [];

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir().'/anime-description-sources-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->configPath, $this->configPath.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function controller(bool $csrfValid = true): DescriptionSourcesController
    {
        $candidates = $this->createStub(DescriptionSourceCandidates::class);
        $candidates->method('list')->willReturn([
            ['id' => 'animedb-a', 'name' => 'A'],
            ['id' => 'animedb-b', 'name' => 'B'],
            ['id' => 'animedb-c', 'name' => 'C'],
        ]);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn($csrfValid);
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $template, array $params): string {
            $this->rendered = $params;

            return '';
        });

        return new DescriptionSourcesController($candidates, new AppSettingsProvider(new AppConfigStore($this->configPath)), $csrf, $twig);
    }

    /** @return list<string> */
    private function saved(): array
    {
        return (new AppSettingsProvider(new AppConfigStore($this->configPath)))->getDescriptionSourcePriority();
    }

    public function testSaveWritesThePriorityAndNothingElse(): void
    {
        $this->controller()->save(new Request([], ['_token' => 't', 'order' => ['animedb-c', 'animedb-a', 'animedb-b']]));

        $this->assertSame(['animedb-c', 'animedb-a', 'animedb-b'], $this->saved());
        $this->assertSame(['descriptionSourcePriority' => ['animedb-c', 'animedb-a', 'animedb-b']], json_decode((string) file_get_contents($this->configPath), true));
        $this->assertTrue($this->rendered['saved']);
        // The controller has no way to dispatch: saving must not enqueue saturation or a recount.
        $constructor = new \ReflectionMethod(DescriptionSourcesController::class, '__construct');
        foreach ($constructor->getParameters() as $parameter) {
            $this->assertNotSame(MessageBusInterface::class, (string) $parameter->getType());
        }
    }

    public function testSaveIgnoresUnknownIdsAndKeepsMissingCandidates(): void
    {
        $this->controller()->save(new Request([], ['_token' => 't', 'order' => ['animedb-b', 'animedb-zzz', 'animedb-b']]));

        $this->assertSame(['animedb-b', 'animedb-a', 'animedb-c'], $this->saved());
    }

    public function testMoveReordersTheListWithoutSaving(): void
    {
        $this->controller()->save(new Request([], ['_token' => 't', 'order' => ['animedb-a', 'animedb-b', 'animedb-c'], 'move' => 'animedb-b:up']));

        $this->assertSame(['animedb-b', 'animedb-a', 'animedb-c'], array_column($this->rendered['items'], 'id'));
        $this->assertFalse($this->rendered['saved']);
        $this->assertFileDoesNotExist($this->configPath);
    }

    public function testMoveOutOfBoundsKeepsTheOrder(): void
    {
        $this->controller()->save(new Request([], ['_token' => 't', 'order' => ['animedb-a', 'animedb-b', 'animedb-c'], 'move' => 'animedb-a:up']));

        $this->assertSame(['animedb-a', 'animedb-b', 'animedb-c'], array_column($this->rendered['items'], 'id'));
    }

    public function testInvalidCsrfTokenIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->controller(csrfValid: false)->save(new Request([], ['_token' => 'bad', 'order' => ['animedb-a']]));
    }
}
