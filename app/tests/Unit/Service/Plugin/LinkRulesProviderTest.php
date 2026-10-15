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

use App\Service\Plugin\LinkRulesProvider;
use App\Tests\Support\BuildsLinkRulesPlugins;
use App\Tests\Support\TemporaryDirectories;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class LinkRulesProviderTest extends TestCase
{
    use BuildsLinkRulesPlugins;
    use TemporaryDirectories;

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectories();
    }

    public function testRulesKeepFileOrderInsideAPlugin(): void
    {
        $this->writeLinkRulesPlugin('acme-rules', ['a(.)' => 'https://x.example/1/$1', 'b(.)' => 'https://x.example/2/$1', 'c(.)' => 'https://x.example/3/$1']);

        $provider = new LinkRulesProvider($this->linkRulesRegistry());

        $this->assertSame(['a(.)', 'b(.)', 'c(.)'], array_keys($provider->rules()));
    }

    public function testPluginWithLargerIdComesFirstAndWinsOnTheSameExpression(): void
    {
        $this->writeLinkRulesPlugin('acme-a', ['same(.)' => 'https://a.example/$1', 'only-a(.)' => 'https://a.example/$1']);
        $this->writeLinkRulesPlugin('acme-b', ['only-b(.)' => 'https://b.example/$1', 'same(.)' => 'https://b.example/$1']);

        $rules = (new LinkRulesProvider($this->linkRulesRegistry()))->rules();

        $this->assertSame(['only-b(.)', 'same(.)', 'only-a(.)'], array_keys($rules));
        $this->assertSame('https://b.example/$1', $rules['same(.)']);
    }

    public function testEmptyRulesAreValidAndAddNothing(): void
    {
        $this->writeLinkRulesPlugin('acme-rules', []);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $this->assertSame([], (new LinkRulesProvider($this->linkRulesRegistry(), logger: $logger))->rules());
    }

    public function testFileThatIsNotYamlIsNotApplied(): void
    {
        $this->writeLinkRulesPlugin('acme-bad', yaml: "rules: [unclosed\n  - : :\n");
        $this->writeLinkRulesPlugin('acme-good', ['good(.)' => 'https://good.example/$1']);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $this->assertSame(['good(.)'], array_keys((new LinkRulesProvider($this->linkRulesRegistry(), logger: $logger))->rules()));
    }

    public function testFileRejectedByTheValidatorIsNotAppliedAsAWhole(): void
    {
        // The second rule uses a group that does not exist, so even the valid first one is dropped.
        $this->writeLinkRulesPlugin('acme-bad', yaml: "version: 1\nrules:\n  'https://bad\\.example/(\\d+)': 'https://bad.example/$1'\n  'https://bad\\.example/x': 'https://bad.example/$9'\n");
        $this->writeLinkRulesPlugin('acme-good', ['good(.)' => 'https://good.example/$1']);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $this->assertSame(['good(.)'], array_keys((new LinkRulesProvider($this->linkRulesRegistry(), logger: $logger))->rules()));
    }

    public function testRulesOfADisabledPluginAreNotApplied(): void
    {
        $this->writeLinkRulesPlugin('acme-off', ['off(.)' => 'https://off.example/$1']);
        $this->writeLinkRulesPlugin('acme-on', ['on(.)' => 'https://on.example/$1']);
        file_put_contents($this->linkRulesPluginsDir().'/plugins.json', (string) json_encode(['acme-off' => ['enabled' => false]]));

        $this->assertSame(['on(.)'], array_keys((new LinkRulesProvider($this->linkRulesRegistry()))->rules()));
    }

    public function testRuleSetIsReReadWhenTheFingerprintChanges(): void
    {
        $this->writeLinkRulesPlugin('acme-rules', ['old(.)' => 'https://x.example/$1']);
        $provider = new LinkRulesProvider($this->linkRulesRegistry());
        $before = $provider->fingerprint();
        $this->assertSame(['old(.)'], array_keys($provider->rules()));

        // The same provider instance stands for a long-lived worker: no restart in between.
        $this->writeLinkRulesPlugin('acme-rules', ['new(.)' => 'https://x.example/$1']);

        $this->assertNotSame($before, $provider->fingerprint());
        $this->assertSame(['new(.)'], array_keys($provider->rules()));
    }

    public function testFingerprintChangesWithPluginVersionAndIsStableOtherwise(): void
    {
        $this->writeLinkRulesPlugin('acme-rules', ['a(.)' => 'https://x.example/$1']);
        $registry = $this->linkRulesRegistry();
        $provider = new LinkRulesProvider($registry);
        $first = $provider->fingerprint();

        $this->assertSame($first, $provider->fingerprint());

        $this->writeLinkRulesPlugin('acme-rules', ['a(.)' => 'https://x.example/$1'], version: '2.0.0');
        $registry->reconcile();
        $this->assertNotSame($first, $provider->fingerprint());
    }

    public function testRuleSetIsNotReparsedWhileTheFingerprintIsUnchanged(): void
    {
        $this->writeLinkRulesPlugin('acme-bad', yaml: "version: 2\nrules: {}\n");
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $provider = new LinkRulesProvider($this->linkRulesRegistry(), logger: $logger);

        $provider->rules();
        $provider->rules();
        $provider->fingerprint();
    }
}
