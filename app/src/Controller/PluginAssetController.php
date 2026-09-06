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

namespace App\Controller;

use App\Entity\ValueObject\Exception\InvalidPluginIdException;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\PluginAssetFingerprint;
use App\Service\Plugin\PluginAssetResolver;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves a single static file (stylesheet, script or image) out of an installed plugin's own
 * `assets/` directory, from the host's own origin — the delivery channel a plugin's declared
 * {@see \AnimeDb\PluginContracts\Manifest\PluginUi} (`manifest.json`'s `ui.css`/`ui.js`, wired
 * into the page shell by {@see AnimeController} and
 * {@see Settings\PluginSettingsController}) and the `plugin_asset()` Twig function
 * ({@see \App\Twig\PluginAssetExtension}) both resolve their URLs against.
 *
 * This is the standard path for a plugin to expose static files to the pages the host renders for
 * it, not the only one: a plugin can still declare its own `plugin-routing.yaml`
 * ({@see \App\Kernel::configureRoutes()}) and serve whatever it wants from a route of its own —
 * this controller does not sandbox a plugin's PHP, which already runs in the host's own process.
 *
 * `{fingerprint}` is a content hash of the file (see {@see PluginAssetFingerprint}), not the
 * plugin's version: a mismatch is a 404, never a fallback to serving the file's current bytes
 * under the URL a stale fingerprint named — with `Cache-Control: immutable` below pinning a
 * response for a year, silently substituting content at an unchanged URL would let a plugin
 * reinstalled with edited files (issue #251) get served through a cache indefinitely under the
 * old hash.
 */
final class PluginAssetController
{
    /**
     * Every extension a plugin's static asset may use — deliberately narrow, and deliberately not
     * resolved by sniffing the file's actual content: {@see Response}'s `Content-Type` is always
     * taken from this map, so a `.svg` whose bytes happen to look like HTML is still served as
     * `image/svg+xml`, never interpreted as markup by the browser.
     *
     * Extension matching against $path is case-sensitive against these lowercase keys — the
     * plugin monorepo's publish gate already requires lowercase extensions under `assets/`
     * (`anime-db/anime-db-plugins#129`), so `assets/Logo.SVG` is simply not a file this route
     * needs to ever have matched, and normalizing case here would only widen what it accepts
     * beyond what a plugin is allowed to publish.
     */
    private const array CONTENT_TYPES = [
        'css' => 'text/css',
        'js' => 'text/javascript',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'woff2' => 'font/woff2',
    ];

    private const int CACHE_MAX_AGE_SECONDS = 31_536_000;

    public function __construct(
        private readonly PluginAssetResolver $assets,
    ) {
    }

    #[Route(
        '/plugin/{pluginId}/asset/{fingerprint}/{path}',
        name: 'plugin_asset',
        requirements: ['path' => '.+'],
        methods: ['GET'],
    )]
    public function __invoke(string $pluginId, string $fingerprint, string $path): Response
    {
        try {
            $id = new PluginId($pluginId);
        } catch (InvalidPluginIdException) {
            throw new NotFoundHttpException(\sprintf('Unknown plugin "%s".', $pluginId));
        }

        $plugin = $this->assets->findEnabledPlugin($id);
        if ($plugin === null) {
            throw new NotFoundHttpException(\sprintf('Plugin "%s" is not installed or is disabled.', $pluginId));
        }

        $absolutePath = $this->assets->resolveContainedFile($plugin, $path);
        if ($absolutePath === null) {
            throw new NotFoundHttpException(\sprintf('Asset "%s" of plugin "%s" was not found.', $path, $pluginId));
        }

        $extension = pathinfo($path, \PATHINFO_EXTENSION);
        $contentType = self::CONTENT_TYPES[$extension] ?? null;
        if ($contentType === null) {
            throw new NotFoundHttpException(\sprintf('File extension of "%s" is not servable as a plugin asset.', $path));
        }

        if (!hash_equals(PluginAssetFingerprint::forFile($absolutePath), $fingerprint)) {
            throw new NotFoundHttpException(\sprintf('Fingerprint of asset "%s" of plugin "%s" does not match.', $path, $pluginId));
        }

        $contents = file_get_contents($absolutePath);
        if ($contents === false) {
            throw new NotFoundHttpException(\sprintf('Asset "%s" of plugin "%s" could not be read.', $path, $pluginId));
        }

        $response = new Response($contents);
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setPrivate();
        $response->setMaxAge(self::CACHE_MAX_AGE_SECONDS);
        $response->setImmutable();

        return $response;
    }
}
