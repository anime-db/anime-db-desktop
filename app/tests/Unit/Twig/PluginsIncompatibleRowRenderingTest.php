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

use AnimeDb\PluginContracts\Manifest\ManifestParser;
use App\Service\Plugin\InstalledPlugin;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

final class PluginsIncompatibleRowRenderingTest extends KernelTestCase
{
    public function testIncompatiblePluginLinksToTheMarketWithoutPromisingAnUpdate(): void
    {
        $html = $this->render($this->plugin(false), []);

        self::assertStringContainsString('data-control="plugin-incompatible-market-link"', $html);
        self::assertStringContainsString('Check the plugin market', $html);
        self::assertStringNotContainsString('is available', $html);
    }

    public function testIncompatiblePluginWithAMarketUpdateNamesTheVersion(): void
    {
        $html = $this->render($this->plugin(false), ['animedb-shikimori' => '1.3.0']);

        self::assertStringContainsString('data-control="plugin-incompatible-market-link"', $html);
        self::assertStringContainsString('Update to version 1.3.0 is available', $html);
        self::assertStringNotContainsString('Check the plugin market', $html);
    }

    public function testCompatiblePluginRowHasNoMarketLinkOrUpdateMention(): void
    {
        $html = $this->render($this->plugin(true), []);

        self::assertStringNotContainsString('plugin-incompatible-market-link', $html);
        self::assertStringNotContainsString('Check the plugin market', $html);
        self::assertStringNotContainsString('is available', $html);
    }

    /**
     * @param array<string, string> $marketUpdates
     */
    private function render(InstalledPlugin $plugin, array $marketUpdates): string
    {
        self::bootKernel();

        $request = Request::create('/settings/plugins');
        $request->setLocale('en');
        $request->setSession(new Session(new MockArraySessionStorage()));
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return $twig->render('settings/plugins/index.html.twig', [
            'installedPlugins' => [$plugin],
            'settingsPluginIds' => [],
            'translationCoverage' => [],
            'pluginLocales' => [],
            'marketUpdates' => $marketUpdates,
            'installedPluginId' => null,
            'updatedPluginId' => null,
            'removedPluginId' => null,
            'installError' => null,
            'installErrorParams' => [],
            'syntaxErrors' => [],
            'manifestErrors' => [],
        ]);
    }

    private function plugin(bool $compatible): InstalledPlugin
    {
        $manifest = (new ManifestParser())->parse((string) json_encode([
            'id' => 'animedb-shikimori',
            'name' => 'Shikimori',
            'version' => '1.1.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        return new InstalledPlugin($manifest, '/tmp/plugin', $compatible, $compatible);
    }
}
