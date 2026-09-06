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

namespace App\Twig;

use App\Entity\ValueObject\Exception\InvalidPluginIdException;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\Exception\PluginAssetNotFoundException;
use App\Service\Plugin\PluginAssetFingerprint;
use App\Service\Plugin\PluginAssetResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `plugin_asset('<plugin-id>', 'assets/img/logo.svg')` — the URL of a single file under an
 * installed plugin's `assets/` directory, for a plugin's own markup to reference (an image, most
 * commonly) rather than for the host-injected `ui.css`/`ui.js` tags, which
 * {@see \App\Controller\AnimeController} and {@see \App\Controller\Settings\PluginSettingsController}
 * already resolve directly from the manifest. The plugin id is an explicit argument rather than
 * inferred from the current template: there is no "current plugin" in the Twig context, and
 * guessing it from a template's namespace would break the moment that template is `include`d from
 * another one.
 *
 * A CSS file's own relative `url(...)` references resolve against the stylesheet's own URL in the
 * browser and land on this same route without any help from this function.
 */
final class PluginAssetExtension extends AbstractExtension
{
    public function __construct(
        private readonly PluginAssetResolver $assets,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('plugin_asset', $this->generate(...)),
        ];
    }

    /**
     * @throws PluginAssetNotFoundException when $pluginId is not an installed, enabled plugin, or
     *                                      $path does not name an existing file under its `assets/` directory
     */
    public function generate(string $pluginId, string $path): string
    {
        try {
            $id = new PluginId($pluginId);
        } catch (InvalidPluginIdException $exception) {
            throw new PluginAssetNotFoundException(\sprintf('Unknown plugin "%s".', $pluginId), previous: $exception);
        }

        $plugin = $this->assets->findEnabledPlugin($id);
        if ($plugin === null) {
            throw new PluginAssetNotFoundException(\sprintf('Unknown plugin "%s".', $pluginId));
        }

        $absolutePath = $this->assets->resolveContainedFile($plugin, $path);
        if ($absolutePath === null) {
            throw new PluginAssetNotFoundException(\sprintf('Asset "%s" of plugin "%s" was not found.', $path, $pluginId));
        }

        return $this->urlGenerator->generate('plugin_asset', [
            'pluginId' => (string) $id,
            'fingerprint' => PluginAssetFingerprint::forFile($absolutePath),
            'path' => $path,
        ]);
    }
}
