<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\ValueObject\Exception\InvalidPluginIdException;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\CatalogWidgetRegistry;
use App\Service\Plugin\EntryWidgetRegistry;
use App\Service\Plugin\Exception\WidgetHardLimitExceededException;
use App\Service\Plugin\InstalledPlugin;
use App\Service\Plugin\InstalledPluginsRegistry;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Widget management for issue #213: every widget of every installed plugin is toggled
 * independently (never a single "whole plugin" switch — that is {@see InstalledPluginsRegistry}'s
 * `enabled`, a separate concern), with a hard cap and a soft recommendation on how many widgets
 * may be active at once *per placement* (anime detail page vs. catalog, counted separately).
 *
 * Rows are listed in plugin installation order ({@see InstalledPluginsRegistry::all()}), matching
 * the same order the widgets themselves are rendered in on the anime detail page
 * ({@see EntryWidgetRegistry::findAllActive()}) — simple ordinal ordering, no drag&drop.
 */
final class PluginWidgetController
{
    public function __construct(
        private readonly InstalledPluginsRegistry $installedPlugins,
        private readonly EntryWidgetRegistry $entryWidgets,
        private readonly CatalogWidgetRegistry $catalogWidgets,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/settings/plugins/widgets', name: 'settings_plugin_widgets_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->renderIndex((string) $request->query->get('error'));
    }

    #[Route('/settings/plugins/widgets/{pluginId}/{widgetName}', name: 'settings_plugin_widgets_toggle', methods: ['POST'])]
    public function toggle(string $pluginId, string $widgetName, Request $request): RedirectResponse
    {
        $this->assertValidCsrfToken('settings_plugin_widgets_toggle_'.$pluginId.'_'.$widgetName, $request);

        try {
            $id = new PluginId($pluginId);
        } catch (InvalidPluginIdException) {
            throw new NotFoundHttpException(\sprintf('Unknown plugin "%s".', $pluginId));
        }

        $placement = (string) $request->request->get('placement', '');
        $active = '1' === (string) $request->request->get('active', '0');

        try {
            match ($placement) {
                'entry' => $this->entryWidgets->setActive($id, $widgetName, $active),
                'catalog' => $this->catalogWidgets->setActive($id, $widgetName, $active),
                default => throw new BadRequestHttpException('Query parameter "placement" must be "entry" or "catalog".'),
            };
        } catch (WidgetHardLimitExceededException) {
            return new RedirectResponse($this->urlGenerator->generate('settings_plugin_widgets_index', ['error' => 'hard_limit_exceeded']));
        }

        return new RedirectResponse($this->urlGenerator->generate('settings_plugin_widgets_index'));
    }

    private function renderIndex(string $error): Response
    {
        $installedPlugins = $this->installedPlugins->all();
        $pluginNames = array_combine(
            array_map(static fn (InstalledPlugin $plugin): string => (string) $plugin->id, $installedPlugins),
            array_map(static fn (InstalledPlugin $plugin): string => $plugin->manifest->name, $installedPlugins),
        );
        $installOrder = array_flip(array_keys($pluginNames));

        return new Response($this->twig->render('settings/plugin/widgets.html.twig', [
            'entryWidgets' => $this->decorate($this->entryWidgets->listAll(), $pluginNames, $installOrder),
            'catalogWidgets' => $this->decorate($this->catalogWidgets->listAll(), $pluginNames, $installOrder),
            'entryActiveCount' => \count($this->entryWidgets->findAllActive()),
            'catalogActiveCount' => \count($this->catalogWidgets->findAllActive()),
            'hardLimit' => EntryWidgetRegistry::HARD_LIMIT,
            'recommendedLimit' => EntryWidgetRegistry::RECOMMENDED_LIMIT,
            'error' => '' !== $error ? $error : null,
        ]));
    }

    /**
     * @param list<array{pluginId: string, widgetName: string, active: bool}> $widgets
     * @param array<string, string>                                           $pluginNames
     * @param array<string, int>                                              $installOrder
     *
     * @return list<array{pluginId: string, pluginName: string, widgetName: string, active: bool}>
     */
    private function decorate(array $widgets, array $pluginNames, array $installOrder): array
    {
        $decorated = array_map(static fn (array $widget): array => [
            ...$widget,
            'pluginName' => $pluginNames[$widget['pluginId']] ?? $widget['pluginId'],
        ], $widgets);

        usort(
            $decorated,
            static fn (array $a, array $b): int => ($installOrder[$a['pluginId']] ?? \PHP_INT_MAX) <=> ($installOrder[$b['pluginId']] ?? \PHP_INT_MAX),
        );

        return $decorated;
    }

    private function assertValidCsrfToken(string $tokenId, Request $request): void
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
