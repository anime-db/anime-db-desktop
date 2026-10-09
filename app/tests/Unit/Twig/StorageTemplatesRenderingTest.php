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

namespace App\Tests\Unit\Twig;

use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * Issue #853 review: StorageControllerTest/StorageEditControllerTest mock Twig and only assert
 * which variables the controller passes it (e.g. `isPreset === true`) — that proves nothing about
 * what the templates actually do with those variables. These tests render storage/list.html.twig
 * and storage/edit.html.twig for real (as IconActionButtonsRenderingTest already does for the
 * icon buttons) so a regression in the templates themselves — the delete form reappearing for the
 * preset storage, the path field losing its `readonly`, the error block no longer rendering —
 * fails here instead of only being caught by a human.
 */
final class StorageTemplatesRenderingTest extends KernelTestCase
{
    private function pushRequestWithSession(string $path): void
    {
        $request = Request::create($path);
        $request->setLocale('en');
        $request->setSession(new Session(new MockArraySessionStorage()));

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);
    }

    private function makeStorage(string $name, int $id, StorageType $type = StorageType::Folder): Storage
    {
        $storage = new Storage($name, sys_get_temp_dir(), $type);
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($storage, $id);

        return $storage;
    }

    public function testStorageListHidesDeleteFormForThePresetStorageOnly(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/storage');

        $preset = $this->makeStorage('AnimeDB', 1);
        $other = $this->makeStorage('Secondary', 2);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('storage/list.html.twig', [
            'storages' => [$preset, $other],
            'unavailableStorageIds' => [],
            'presetStorageId' => 1,
            'error' => null,
        ]);

        self::assertStringNotContainsString('storage/1/delete', $html);
        self::assertStringContainsString('storage/2/delete', $html);
    }

    public function testStorageListShowsTheScanButtonOnlyForScannableStorages(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/storage');

        $folder = $this->makeStorage('Folder', 1);
        $video = $this->makeStorage('Video', 2, StorageType::Video);
        $externalR = $this->makeStorage('Optical', 3, StorageType::ExternalR);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('storage/list.html.twig', [
            'storages' => [$folder, $video, $externalR],
            'unavailableStorageIds' => [],
            'presetStorageId' => null,
            'error' => null,
        ]);

        self::assertStringContainsString('action="/storage/1/scan"', $html);
        self::assertStringNotContainsString('action="/storage/2/scan"', $html);
        self::assertStringNotContainsString('action="/storage/3/scan"', $html);
    }

    public function testStorageNewHasCancelButtonToTheListInsteadOfSkipLink(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/storage/new');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('storage/new.html.twig', [
            'name' => '',
            'path' => '',
            'type' => null,
            'error' => null,
            'types' => ['folder'],
            'path_optional_types' => [],
            'path_not_applicable_types' => [],
            'writable_types' => ['folder'],
        ]);

        self::assertMatchesRegularExpression('#<a href="/storage" class="btn btn-outline-secondary">Cancel</a>#', $html);
        self::assertStringNotContainsString('Skip', $html);
    }

    public function testStorageListRendersTranslatedErrorWithStorageName(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/storage');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('storage/list.html.twig', [
            'storages' => [],
            'unavailableStorageIds' => [],
            'presetStorageId' => null,
            'error' => 'storage_list.delete_error_unfinished_downloads',
            'errorParams' => ['%name%' => 'Main folder'],
        ]);

        self::assertMatchesRegularExpression('/alert-danger[^"]*"[^>]*>[^<]*Main folder/s', $html);
    }

    public function testStorageEditRendersPathFieldReadonlyOnlyForPresetStorage(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/storage/1/edit');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('storage/edit.html.twig', [
            'storage' => $this->makeStorage('AnimeDB', 1),
            'name' => 'AnimeDB',
            'path' => sys_get_temp_dir(),
            'type' => StorageType::Folder->value,
            'error' => null,
            'errorParams' => [],
            'types' => array_column(StorageType::cases(), 'value'),
            'nonWritableTypes' => [],
            'pathOptionalTypes' => ['external-r', 'video'],
            'pathNotApplicableTypes' => ['video'],
            'isPreset' => true,
        ]);

        self::assertMatchesRegularExpression('/<input[^>]*id="storage-edit-path"[^>]*\breadonly\b/', $html);
    }

    public function testStorageEditDoesNotRenderPathFieldReadonlyForNonPresetStorage(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/storage/2/edit');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('storage/edit.html.twig', [
            'storage' => $this->makeStorage('Secondary', 2),
            'name' => 'Secondary',
            'path' => sys_get_temp_dir(),
            'type' => StorageType::Folder->value,
            'error' => null,
            'errorParams' => [],
            'types' => array_column(StorageType::cases(), 'value'),
            'nonWritableTypes' => [],
            'pathOptionalTypes' => ['external-r', 'video'],
            'pathNotApplicableTypes' => ['video'],
            'isPreset' => false,
        ]);

        self::assertDoesNotMatchRegularExpression('/<input[^>]*id="storage-edit-path"[^>]*\breadonly\b/', $html);
    }

    public function testStorageEditDisablesNonWritableTypeOptionsForPresetStorage(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/storage/1/edit');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('storage/edit.html.twig', [
            'storage' => $this->makeStorage('AnimeDB', 1),
            'name' => 'AnimeDB',
            'path' => sys_get_temp_dir(),
            'type' => StorageType::Folder->value,
            'error' => null,
            'errorParams' => [],
            'types' => array_column(StorageType::cases(), 'value'),
            'nonWritableTypes' => ['external-r', 'video'],
            'pathOptionalTypes' => ['external-r', 'video'],
            'pathNotApplicableTypes' => ['video'],
            'isPreset' => true,
        ]);

        self::assertMatchesRegularExpression('/<option value="video"[^>]*\bdisabled\b/', $html);
        self::assertDoesNotMatchRegularExpression('/<option value="folder"[^>]*\bdisabled\b/', $html);
    }

    public function testStorageEditRendersTranslatedErrorWithStorageName(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/storage/1/edit');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('storage/edit.html.twig', [
            'storage' => $this->makeStorage('AnimeDB', 1),
            'name' => 'AnimeDB',
            'path' => sys_get_temp_dir(),
            'type' => StorageType::Folder->value,
            'error' => 'storage_edit.error_preset_type',
            'errorParams' => ['%name%' => 'AnimeDB'],
            'types' => array_column(StorageType::cases(), 'value'),
            'nonWritableTypes' => ['external-r', 'video'],
            'pathOptionalTypes' => ['external-r', 'video'],
            'pathNotApplicableTypes' => ['video'],
            'isPreset' => true,
        ]);

        self::assertMatchesRegularExpression('/alert-danger[^"]*"[^>]*>[^<]*AnimeDB/s', $html);
    }

    /**
     * @return iterable<string, array{string, bool, bool, bool}> type, disabled, required, hint visible
     */
    public static function pathStateProvider(): iterable
    {
        yield 'video' => ['video', true, false, true];
        yield 'external-r' => ['external-r', false, false, false];
        yield 'folder' => ['folder', false, true, false];
        yield 'external' => ['external', false, true, false];
    }

    #[DataProvider('pathStateProvider')]
    public function testStorageEditPathFieldState(string $type, bool $disabled, bool $required, bool $hintVisible): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/storage/3/edit');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('storage/edit.html.twig', [
            'storage' => $this->makeStorage('AnimeDB', 3),
            'name' => 'AnimeDB',
            'path' => '/some/path',
            'type' => $type,
            'error' => null,
            'errorParams' => [],
            'types' => array_column(StorageType::cases(), 'value'),
            'nonWritableTypes' => [],
            'pathOptionalTypes' => ['external-r', 'video'],
            'pathNotApplicableTypes' => ['video'],
            'isPreset' => false,
        ]);

        $input = self::firstMatch('/<input[^>]*id="storage-edit-path"[^>]*>/', $html);
        self::assertSame($disabled, (bool) preg_match('/\bdisabled\b/', $input));
        self::assertSame($required, (bool) preg_match('/\brequired\b/', $input));
        self::assertSame($disabled, !str_contains($input, '/some/path'));
        $hint = self::firstMatch('/<div id="storage-edit-path-not-applicable"[^>]*>/', $html);
        self::assertSame($hintVisible, !str_contains($hint, 'hidden'));
    }

    #[DataProvider('pathStateProvider')]
    public function testStorageNewPathFieldState(string $type, bool $disabled, bool $required, bool $hintVisible): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/storage/new');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('storage/new.html.twig', [
            'name' => 'AnimeDB',
            'path' => '/some/path',
            'type' => $type,
            'error' => null,
            'types' => array_column(StorageType::cases(), 'value'),
            'path_optional_types' => ['external-r', 'video'],
            'path_not_applicable_types' => ['video'],
            'writable_types' => ['folder', 'external'],
        ]);

        $input = self::firstMatch('/<input[^>]*id="storage-new-path"[^>]*>/', $html);
        self::assertSame($disabled, (bool) preg_match('/\bdisabled\b/', $input));
        self::assertSame($required, (bool) preg_match('/\brequired\b/', $input));
        self::assertSame($disabled, !str_contains($input, '/some/path'));
        $hint = self::firstMatch('/<div id="storage-new-path-not-applicable"[^>]*>/', $html);
        self::assertSame($hintVisible, !str_contains($hint, 'hidden'));
    }

    private static function firstMatch(string $pattern, string $subject): string
    {
        self::assertSame(1, preg_match($pattern, $subject, $matches));

        return (string) ($matches[0] ?? '');
    }
}
