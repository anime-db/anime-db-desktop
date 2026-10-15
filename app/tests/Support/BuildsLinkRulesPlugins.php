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

namespace App\Tests\Support;

use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds a plugins directory holding rule-only plugins from rule fixtures written by the test, so
 * nothing here depends on a real plugin. Needs {@see TemporaryDirectories}.
 */
trait BuildsLinkRulesPlugins
{
    private string $linkRulesPluginsDir = '';

    private function linkRulesPluginsDir(): string
    {
        if ($this->linkRulesPluginsDir === '') {
            $this->linkRulesPluginsDir = $this->createTemporaryDirectory('anime-link-rules');
        }

        return $this->linkRulesPluginsDir;
    }

    /**
     * @param array<string, string>|null $rules expression => replacement; ignored when $yaml is given
     * @param string|null                $yaml  raw file content
     */
    private function writeLinkRulesPlugin(string $id, ?array $rules = null, ?string $yaml = null, string $version = '1.0.0'): void
    {
        $dir = $this->linkRulesPluginsDir().'/'.$id;
        if (!is_dir($dir)) {
            mkdir($dir, recursive: true);
        }
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $id,
            'name' => ucfirst($id),
            'version' => $version,
            'type' => 'local',
            'link_rules' => true,
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
        if ($yaml === null) {
            $yaml = "version: 1\nrules:".($rules === [] || $rules === null ? " {}\n" : "\n");
            foreach ($rules ?? [] as $expression => $replacement) {
                $yaml .= '  '.json_encode((string) $expression, \JSON_UNESCAPED_SLASHES).': '.json_encode($replacement, \JSON_UNESCAPED_SLASHES)."\n";
            }
        }
        file_put_contents($dir.'/link-rules.yaml', $yaml);
    }

    private function linkRulesRegistry(?LoggerInterface $logger = null): InstalledPluginsRegistry
    {
        $registry = new InstalledPluginsRegistry(
            $this->linkRulesPluginsDir(),
            new PluginsConfigStore($this->linkRulesPluginsDir().'/plugins.json'),
            $logger ?? new NullLogger(),
        );
        $registry->reconcile();

        return $registry;
    }
}
