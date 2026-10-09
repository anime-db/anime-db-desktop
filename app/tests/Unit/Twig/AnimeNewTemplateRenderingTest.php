<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 *
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

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class AnimeNewTemplateRenderingTest extends KernelTestCase
{
    public function testTypeSelectHasAnEmptyRequiredChoiceAndAHintAndNoPreselectedType(): void
    {
        $html = $this->renderNew(null);

        self::assertMatchesRegularExpression('#<select id="anime-new-type" name="type" required[^>]*>\s*<option value="" selected disabled>— select —</option>#u', $html);
        self::assertStringNotContainsString('value="tv" selected', $html);
        self::assertStringContainsString('The type cannot be changed after the entry is created.', $html);
    }

    public function testTypeSelectKeepsTheChosenTypeAfterAValidationError(): void
    {
        $html = $this->renderNew('movie');

        self::assertStringContainsString('<option value="movie" selected>', $html);
        self::assertStringNotContainsString('value="" selected', $html);
    }

    public function testFormHasACancelButtonToTheCatalogNextToSubmit(): void
    {
        $html = $this->renderNew(null);

        self::assertMatchesRegularExpression('#<button type="submit" class="btn btn-primary">Create</button>\s*<a href="/" class="btn btn-outline-secondary">Cancel</a>#', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        yield 'en' => ['en'];
        yield 'ru' => ['ru'];
    }

    #[DataProvider('locales')]
    public function testEditPageStudiosFilterPlaceholderKeyIsTranslated(string $locale): void
    {
        self::bootKernel();

        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get('translator');

        self::assertNotSame('anime_edit.filter_studios', $translator->trans('anime_edit.filter_studios', [], null, $locale));
    }

    private function renderNew(?string $type): string
    {
        self::bootKernel();

        $request = Request::create('/anime/new');
        $request->setLocale('en');
        $request->setSession(new Session(new MockArraySessionStorage()));
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return $twig->render('anime/new.html.twig', [
            'title' => '',
            'type' => $type,
            'watch_status' => 'plan',
            'storage_id' => null,
            'storage_path' => null,
            'error' => null,
            'types' => ['tv', 'movie'],
            'watch_statuses' => ['plan'],
        ]);
    }
}
