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
use App\Entity\Enum\StorageType;
use App\Entity\Label;
use App\Entity\Storage;
use App\Service\Plugin\InstalledPlugin;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * Issue #828: action-column buttons in the storage, plugins and labels tables lost their text and
 * became icon-only — every one of them must still carry an `aria-label`/`title` with the original
 * wording, and the icon it wraps must stay decorative (`aria-hidden="true"`).
 *
 * Issue #829 review: asserting only that *some* `aria-hidden` svg sits inside the button cannot
 * tell a correctly-wired button apart from one that got the wrong icon (e.g. "pencil" on a
 * Delete button) — every file in templates/icons/ carries that same attribute. assertIconButton()
 * now also pins the `data-icon` App\Twig\IconExtension stamps onto the rendered svg, so a
 * mismatch between the label and the icon actually shown fails the test.
 *
 * Issue #829 review (second pass): these buttons also carry `btn-icon`, the class that centers
 * the icon on the button's geometric center via flexbox instead of the `<svg>`'s default
 * `vertical-align: middle` (which centers on font x-height, off by a fraction of a pixel).
 * assertIconButton() pins that class too, so dropping it from one button regresses silently.
 */
final class IconActionButtonsRenderingTest extends KernelTestCase
{
    private function pushRequestWithSession(string $path): void
    {
        $request = Request::create($path);
        $request->setLocale('en');
        $request->setSession(new Session(new MockArraySessionStorage()));

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);
    }

    private function assertIconButton(string $html, string $label, string $icon): void
    {
        $pattern = '/<(?:a|button)[^>]*\bclass="[^"]*\bbtn-icon\b[^"]*"[^>]*title="'.preg_quote($label, '/').'"[^>]*aria-label="'.preg_quote($label, '/').'"[^>]*>\s*<svg[^>]*data-icon="'.preg_quote($icon, '/').'"[^>]*aria-hidden="true"/s';
        self::assertMatchesRegularExpression(
            $pattern,
            $html,
            \sprintf('Expected a "btn-icon" button labelled "%s" with its "%s" icon (aria-hidden) inside.', $label, $icon),
        );
    }

    public function testStorageListActionButtonsCarryTitleAndAriaLabel(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/storage');

        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($storage, 1);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('storage/list.html.twig', [
            'storages' => [$storage],
            'unavailableStorageIds' => [],
            'scanned' => false,
            'scannedStorageId' => null,
        ]);

        $this->assertIconButton($html, 'Edit', 'pencil');
        $this->assertIconButton($html, 'Scan', 'arrow-repeat');
        $this->assertIconButton($html, 'Delete', 'trash');
    }

    public function testPluginsIndexActionButtonsCarryTitleAndAriaLabel(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/settings/plugins');

        $manifest = (new ManifestParser())->parse((string) json_encode([
            'id' => 'animedb-shikimori',
            'name' => 'Shikimori',
            'version' => '1.1.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
        $plugin = new InstalledPlugin($manifest, '/tmp/plugin', true, true);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/plugins/index.html.twig', [
            'installedPlugins' => [$plugin],
            'settingsPluginIds' => ['animedb-shikimori'],
            'translationCoverage' => [],
            'pluginLocales' => [],
            'marketUpdates' => [],
            'installedPluginId' => null,
            'updatedPluginId' => null,
            'removedPluginId' => null,
            'installError' => null,
            'installErrorParams' => [],
            'syntaxErrors' => [],
            'manifestErrors' => [],
        ]);

        $this->assertIconButton($html, 'Settings', 'gear');
        $this->assertIconButton($html, 'Remove', 'trash');
    }

    public function testLabelsIndexDeleteButtonCarriesTitleAndAriaLabel(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/settings/labels');

        $label = new Label();
        $label->rename('favorite');
        (new \ReflectionProperty(Label::class, 'id'))->setValue($label, 1);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/label/index.html.twig', [
            'labels' => [$label],
            'labelCounts' => [1 => 0],
            'error' => null,
        ]);

        $this->assertIconButton($html, 'Delete', 'trash');
    }
}
