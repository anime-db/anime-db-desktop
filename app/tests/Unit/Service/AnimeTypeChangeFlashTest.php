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

use App\Entity\Enum\AnimeType;
use App\Service\AnimeTypeChangeFlash;
use App\Service\AnimeTypeChangeOutcome;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

final class AnimeTypeChangeFlashTest extends TestCase
{
    public function testChangedReportsSuccessWithTargetType(): void
    {
        $request = $this->request();
        $this->flash()->add($request, AnimeTypeChangeOutcome::Changed, AnimeType::Movie);

        self::assertSame($this->message('success', 'changed:movie'), $this->messages($request));
    }

    public function testChangedTextDependsOnTargetType(): void
    {
        $movie = $this->request();
        $ova = $this->request();
        $this->flash()->add($movie, AnimeTypeChangeOutcome::Changed, AnimeType::Movie);
        $this->flash()->add($ova, AnimeTypeChangeOutcome::Changed, AnimeType::Ova);

        self::assertNotSame($this->messages($movie), $this->messages($ova));
    }

    public function testSyncRunning(): void
    {
        $request = $this->request();
        $this->flash()->add($request, AnimeTypeChangeOutcome::SyncRunning, AnimeType::Movie);

        self::assertSame($this->message('danger', 'anime_type_change.flash_sync_running'), $this->messages($request));
    }

    public function testLossNotConfirmed(): void
    {
        $request = $this->request();
        $this->flash()->add($request, AnimeTypeChangeOutcome::LossNotConfirmed, AnimeType::Movie);

        self::assertSame($this->message('danger', 'anime_type_change.flash_not_confirmed'), $this->messages($request));
    }

    public function testInvalid(): void
    {
        $request = $this->request();
        $this->flash()->addInvalid($request);

        self::assertSame($this->message('danger', 'anime_type_change.flash_invalid'), $this->messages($request));
    }

    private function flash(): AnimeTypeChangeFlash
    {
        // Untranslated keys come back as is; only the keys with a placeholder need a message.
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'anime_type_change.flash_changed' => 'changed:%type%',
            'anime_type.movie' => 'movie',
            'anime_type.ova' => 'ova',
        ], 'en');

        return new AnimeTypeChangeFlash($translator);
    }

    private function request(): Request
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    /**
     * @return array<string, list<array{text: string, link_url: null, link_label: null}>>
     */
    private function message(string $type, string $text): array
    {
        return [$type => [['text' => $text, 'link_url' => null, 'link_label' => null]]];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function messages(Request $request): array
    {
        $session = $request->getSession();
        \assert($session instanceof FlashBagAwareSessionInterface);

        return $session->getFlashBag()->all();
    }
}
