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

        self::assertMatchesRegularExpression($this->marketLinkPattern('Check the plugin market'), $html);
        self::assertStringNotContainsString('is available', $html);
    }

    public function testIncompatiblePluginWithAMarketUpdateNamesTheVersion(): void
    {
        $html = $this->render($this->plugin(false), ['animedb-shikimori' => '1.3.0']);

        self::assertMatchesRegularExpression($this->marketLinkPattern('Update to version 1.3.0 is available'), $html);
        self::assertStringNotContainsString('Check the plugin market', $html);
    }

    public function testCompatiblePluginRowHasNoMarketLinkOrUpdateMention(): void
    {
        $html = $this->render($this->plugin(true), []);

        self::assertDoesNotMatchRegularExpression($this->marketLinkPattern('Check the plugin market'), $html);
        self::assertStringNotContainsString('Check the plugin market', $html);
        self::assertStringNotContainsString('is available', $html);
    }

    /**
     * Regression (issue #817): the status badge must carry only the short "Incompatible" label,
     * with the longer explanation moved to plain text below it — a badge is sized for one or two
     * words, not a full sentence.
     */
    public function testIncompatiblePluginStatusBadgeShowsShortTextWithExplanationBelow(): void
    {
        $html = $this->render($this->plugin(false), []);

        $matched = preg_match('/<span class="badge text-bg-danger">(.*?)<\/span>/s', $html, $matches);
        self::assertSame(1, $matched, 'Expected the incompatible status badge to be present.');
        self::assertSame('Incompatible', $matches[1]);
        self::assertStringNotContainsString('needs a different app version', $matches[1]);

        self::assertStringContainsString(
            '<div class="small text-body-secondary mt-1">Disabled — this plugin needs a different app version to work.</div>',
            $html,
        );
    }

    public function testRemoveFormAsksForConfirmationNamingThePlugin(): void
    {
        $html = $this->render($this->plugin(true), []);

        $matched = preg_match('#<form[^>]*action="/settings/plugins/animedb-shikimori/remove"[^>]*>#', $html, $matches);
        self::assertSame(1, $matched, 'Expected the remove form to be present.');
        self::assertStringContainsString('data-confirm="', $matches[0]);
        self::assertStringContainsString('Shikimori', $matches[0]);
        self::assertStringContainsString('is kept', $matches[0]);
    }

    public function testDefaultSearchSelectOffersNoneAndMarksTheStoredChoice(): void
    {
        $html = $this->render($this->plugin(true), [], [
            'searchChoices' => [['id' => 'animedb-shikimori', 'name' => 'Shikimori']],
            'selectedSearchId' => 'animedb-shikimori',
        ]);

        self::assertStringContainsString('action="/settings/plugins/default-search"', $html);
        self::assertStringContainsString('data-filter-input', $html);
        self::assertMatchesRegularExpression('#<input[^>]*name="plugin" value=""(?![^>]*checked)[^>]*>#', $html);
        self::assertMatchesRegularExpression('#<input[^>]*name="plugin" value="animedb-shikimori" checked>#', $html);
    }

    public function testDefaultSearchSelectChecksNoneWhenNothingIsStored(): void
    {
        $html = $this->render($this->plugin(true), [], [
            'searchChoices' => [['id' => 'animedb-shikimori', 'name' => 'Shikimori']],
            'selectedSearchId' => '',
        ]);

        self::assertMatchesRegularExpression('#<input[^>]*name="plugin" value="" checked>#', $html);
    }

    public function testDefaultSearchSelectShowsAStoredButUnavailableChoiceAsChecked(): void
    {
        $html = $this->render($this->plugin(true), [], [
            'searchChoices' => [],
            'selectedSearchId' => '',
            'unavailableSearchId' => 'animedb-gone',
        ]);

        self::assertMatchesRegularExpression('#<input[^>]*name="plugin" value="animedb-gone" checked>#', $html);
        self::assertMatchesRegularExpression('#<input[^>]*name="plugin" value=""(?![^>]*checked)[^>]*>#', $html);
        self::assertStringContainsString('still saved', $html);
    }

    /**
     * The page navigation also links to the market, so match the link by its text.
     */
    private function marketLinkPattern(string $text): string
    {
        return '#<a href="/settings/market">\s*'.preg_quote($text, '#').'#';
    }

    /**
     * @param array<string, string> $marketUpdates
     * @param array<string, mixed>  $extra
     */
    private function render(InstalledPlugin $plugin, array $marketUpdates, array $extra = []): string
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
            'syncPluginIds' => [],
            'syncActiveIds' => [],
            'error' => null,
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
        ] + $extra);
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
