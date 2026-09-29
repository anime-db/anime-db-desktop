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

use AnimeDb\PluginContracts\Widget\WidgetListItem;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * plugin/_widget_list.html.twig has no in-repo caller (it is a host-side helper picked up by
 * third-party plugin templates outside this repository), so nothing else in the suite renders it.
 * This pins the card's accessible name to stay non-duplicated (issue #700): the thumbnail is
 * decorative (empty alt) and the visible title text carries the title, once via a `title`
 * attribute for text overflow rather than on the surrounding link.
 *
 * The template's docblock defines {@see WidgetListItem} as the single definition of an `items`
 * entry's shape; a plain array with the same keys is only tolerated because Twig reads
 * object properties and array keys the same way, kept for widgets whose plugin manifest omits
 * the optional `require.plugin-contracts` field and so has no DTO to construct. Both shapes are
 * exercised here to pin down that current tolerance, not to promise it: narrowing the template to
 * `WidgetListItem` only is an allowed evolution, and the array case here would be deleted along
 * with the tolerance it covers, not treated as a broken contract.
 */
final class WidgetListTemplateRenderingTest extends KernelTestCase
{
    /** @return iterable<string, array{0: WidgetListItem|array{thumbnail: string, title: string, subtitle: string|null, url: string}}> */
    public static function widgetListItemShapes(): iterable
    {
        yield 'WidgetListItem instance' => [
            new WidgetListItem(
                thumbnail: 'https://example.test/cover.webp',
                title: 'Sample Title',
                subtitle: null,
                url: 'https://example.test/record',
            ),
        ];
        yield 'array with the same keys' => [
            [
                'thumbnail' => 'https://example.test/cover.webp',
                'title' => 'Sample Title',
                'subtitle' => null,
                'url' => 'https://example.test/record',
            ],
        ];
    }

    /** @param WidgetListItem|array{thumbnail: string, title: string, subtitle: string|null, url: string} $item */
    #[DataProvider('widgetListItemShapes')]
    public function testCardThumbnailIsDecorativeAndLinkDoesNotDuplicateTheTitle(WidgetListItem|array $item): void
    {
        self::bootKernel();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('plugin/_widget_list.html.twig', [
            'items' => [$item],
        ]);

        $this->assertStringContainsString(
            '<img class="anime-card__thumb" src="https://example.test/cover.webp" alt="" loading="lazy">',
            $html,
        );
        $this->assertStringContainsString('<p class="anime-card__title" title="Sample Title">', $html);

        $linkStart = strpos($html, '<a class="anime-card"');
        $this->assertNotFalse($linkStart);
        $linkEnd = strpos($html, '>', $linkStart);
        $this->assertNotFalse($linkEnd);
        $this->assertStringNotContainsString('title=', substr($html, $linkStart, $linkEnd - $linkStart));
    }
}
