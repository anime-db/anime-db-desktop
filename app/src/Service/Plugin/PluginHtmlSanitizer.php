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

namespace App\Service\Plugin;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitizes the raw HTML string returned by a plugin's
 * {@see \AnimeDb\PluginContracts\Settings\SettingsPageInterface::render()} or a
 * `*WidgetInterface::render()` (issue #595) before it reaches an app page. A plugin is
 * unverified third-party code and both contracts only promise "a raw HTML string" — nothing
 * upstream of this class stops that string from containing a `<script>`, an `onclick=`, or a
 * `javascript:` href, and {@see \App\Controller\Settings\PluginSettingsController} /
 * {@see \App\Controller\PluginWidgetController} used to insert it into the page as-is.
 *
 * The allow-list is built explicitly, tag by tag and attribute by attribute, instead of starting
 * from {@see HtmlSanitizerConfig::allowSafeElements()}'s bundled W3C-safe set, so every entry can
 * be traced to markup that actually exists today: the `animedb-shikimori` plugin (currently the
 * only plugin with a settings page or widgets) and the host's own
 * `templates/plugin/_widget_list.html.twig` helper, which other widgets are free to reuse.
 *
 * `hx-*` is intentionally not hardcoded to the handful of attributes any single plugin happens to
 * use today: `symfony/html-sanitizer` drops unrecognized attributes silently (no failing test
 * would ever catch a missing one), and htmx's own attribute surface grows across versions and
 * extensions a plugin author might enable (`hx-ext`, `hx-sse`, `hx-ws`, ...). Enumerating today's
 * three (`hx-post`/`hx-target`/`hx-swap`) would silently strip a fourth added tomorrow. Instead,
 * {@see self::pluginCustomAttributes()} allows every `hx-*`/`data-*` attribute actually present on
 * the input, tracking htmx by construction rather than by an enumerated list — except for a fixed
 * denylist of attributes whose *value* htmx evaluates as a JavaScript expression rather than
 * treating as inert data, the same risk class as `onclick=`:
 * - `hx-on`/`hx-on:*` — runs its value on the named DOM event;
 * - `hx-vals`/`hx-vars`/`hx-headers` — run their value as JavaScript when it carries a
 *   `js:`/`javascript:` prefix.
 * htmx also treats a `data-` prefix as a plain alias for the same attribute (`data-hx-vals` behaves
 * exactly like `hx-vals`), so the denylist below is checked against the name with any `data-`
 * prefix stripped first — none of the above ever reaches the allow-list, under either spelling,
 * even though all of them match the `hx-*`/`data-*` prefix.
 */
final class PluginHtmlSanitizer
{
    private readonly HtmlSanitizerConfig $baseConfig;

    public function __construct()
    {
        $this->baseConfig = self::buildBaseConfig();
    }

    public function sanitize(string $html): string
    {
        $config = $this->baseConfig;
        foreach (self::pluginCustomAttributes($html) as $attribute) {
            // '*' here means "every element already allowed below", not "every element" —
            // see HtmlSanitizerConfig::allowAttribute().
            $config = $config->allowAttribute($attribute, '*');
        }

        return (new HtmlSanitizer($config))->sanitize($html);
    }

    private static function buildBaseConfig(): HtmlSanitizerConfig
    {
        $config = (new HtmlSanitizerConfig())
            // Wrappers: settings.html.twig's `#animedb-shikimori-settings`/`.oauth` divs, the
            // widget list's `.anime-card__thumb--placeholder` fallback, and status/hint text.
            ->allowElement('div')
            ->allowElement('p')
            // settings.html.twig's "Account" section heading.
            ->allowElement('h3')
            // plugin/_widget_list.html.twig's card list.
            ->allowElement('ul')
            ->allowElement('li')
            // Wraps a record's title in the widget list — bidi-isolates plugin-supplied text,
            // not a styling hook, so it carries no attributes of its own.
            ->allowElement('bdi')
            // The settings page's Authorize/reauthorize/back links, and each widget card's link
            // to the plugin's own external page for that record. Per SettingsPageInterface's own
            // contract, "Authorize" must stay a plain top-level navigation — never an
            // HTMX-swapped element — so href/target/rel are real navigation, not decoration.
            ->allowElement('a', ['href', 'target', 'rel'])
            // Widget card thumbnails: absolute http(s) URLs or the app's own `app-media://`
            // media protocol (see native/protocols/app-media.js).
            ->allowElement('img', ['src', 'alt', 'loading'])
            // settings.html.twig's save/disconnect forms. `action`/`method` are kept even though
            // the current plugin only submits via `hx-post`, so a plugin using a plain
            // (non-HTMX) form submit for "Authorize" (also permitted by the contract) still works.
            ->allowElement('form', ['action', 'method'])
            ->allowElement('label', ['for'])
            ->allowElement('input', ['name', 'type', 'value', 'placeholder'])
            ->allowElement('button', ['type'])
            // Every href/action produced by the current plugin markup comes from Twig's path(),
            // which yields a path-absolute URL with no scheme/host. Without this, every such
            // link or form — including the Authorize navigation — would be silently dropped.
            ->allowRelativeLinks()
            ->allowMediaSchemes(['http', 'https', 'data', 'app-media']);
        // `javascript:`/other dangerous href schemes stay blocked: this relies on the library's
        // own default allow-list (['http', 'https', 'mailto', 'tel']), left unchanged on purpose.

        // class/id are inert DOM hooks (styling, HTMX targets, anchors) with no execution
        // surface, so they are allowed everywhere above rather than re-listed per element.
        $config = $config->allowAttribute('class', '*');

        return $config->allowAttribute('id', '*');
    }

    /**
     * `hx-*` attributes whose value htmx can evaluate as JavaScript — see the class docblock.
     * `hx-on`/`hx-on:*` are matched by prefix below, not listed here, since the event name is
     * part of the attribute name itself.
     *
     * @var list<string>
     */
    private const DANGEROUS_HX_ATTRIBUTES = ['hx-vals', 'hx-vars', 'hx-headers'];

    /**
     * @return list<string>
     */
    private static function pluginCustomAttributes(string $html): array
    {
        if (preg_match_all('/[\s"\'](data-[a-z0-9_-]+|hx-[a-z0-9_:-]+)\s*=/i', $html, $matches) === 0) {
            return [];
        }

        $attributes = [];
        foreach (array_unique(array_map(strtolower(...), $matches[1])) as $attribute) {
            // htmx treats a `data-` prefix as a plain alias for the same attribute, so judge
            // `data-hx-vals` by the same rule as `hx-vals` — see the class docblock.
            $logicalName = str_starts_with($attribute, 'data-hx-') ? substr($attribute, 5) : $attribute;

            if ($logicalName === 'hx-on' || str_starts_with($logicalName, 'hx-on:')
                || in_array($logicalName, self::DANGEROUS_HX_ATTRIBUTES, true)
            ) {
                continue;
            }

            $attributes[] = $attribute;
        }

        return $attributes;
    }
}
