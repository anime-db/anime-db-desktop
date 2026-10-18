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

namespace App\Command;

use AnimeDb\PluginContracts\Manifest\PluginType;
use App\Service\AppConfigStore;
use App\Service\Market\MarketPlugin;
use App\Service\Market\PluginRegistryCache;
use App\Service\Market\PluginRegistryFetcher;
use App\Service\Market\PluginRegistryHighWaterMarkStore;
use App\Service\Market\PluginRegistryLoader;
use App\Service\Market\PluginRegistrySignatureVerifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Generates `build/installer-plugins.nsh` (issue #1084): the list of official plugins the Windows
 * installer offers on its plugin selection page. Run by `scripts/build.js` before electron-builder;
 * the installer itself only records the user's choice, it never downloads or installs anything.
 *
 * Only `animedb-*` plugins that have a version compatible with this build's core and
 * plugin-contracts versions are listed (via {@see MarketPlugin::resolveCompatibleVersion()}, the
 * same check the app uses). Versions are not written to the output.
 *
 * The registry must be fresh ({@see \App\Service\Market\PluginRegistryLoadResult::isFresh()}): an
 * unreachable registry, a failed signature check or a cached copy fails the build. The loader is
 * assembled here, over a throwaway directory, rather than taken from the container: loading a
 * registry persists the registry cache and the anti-rollback high-water-mark, and a build must
 * leave nothing of either in the app's own state under `var/`.
 */
#[AsCommand(name: 'app:installer:plugins', description: 'Generate the installer plugin selection list from the signed plugin registry')]
final class InstallerPluginsGenerateCommand extends Command
{
    private const string PLUGIN_ID_PREFIX = 'animedb-';

    public function __construct(
        private readonly PluginRegistryFetcher $fetcher,
        private readonly PluginRegistrySignatureVerifier $signatureVerifier,
        private readonly LoggerInterface $logger,
        private readonly ?string $coreVersion,
        private readonly ?string $pluginContractsVersion,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'output',
            'o',
            InputOption::VALUE_REQUIRED,
            'Path of the generated NSIS include file',
            \dirname($this->projectDir).'/build/installer-plugins.nsh',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->coreVersion === null) {
            $output->writeln('<error>Core version is unknown, cannot check plugin compatibility.</error>');

            return Command::FAILURE;
        }

        $filesystem = new Filesystem();
        $stateDir = sys_get_temp_dir().'/anime-installer-plugins-'.bin2hex(random_bytes(8));
        $filesystem->mkdir($stateDir);

        try {
            $result = (new PluginRegistryLoader(
                $this->fetcher,
                $this->signatureVerifier,
                new PluginRegistryCache($stateDir.'/registry-cache.json', $this->logger),
                new PluginRegistryHighWaterMarkStore(new AppConfigStore($stateDir.'/config.json')),
                $this->logger,
            ))->load();
        } finally {
            $filesystem->remove($stateDir);
        }

        if (!$result->isFresh() || $result->registry === null) {
            $output->writeln(\sprintf(
                '<error>Plugin registry is not fresh: %s</error>',
                $result->error?->getMessage() ?? 'no registry loaded',
            ));

            return Command::FAILURE;
        }

        $plugins = [];
        foreach ($result->registry->plugins() as $plugin) {
            if (!str_starts_with((string) $plugin->id, self::PLUGIN_ID_PREFIX)
                || $plugin->resolveCompatibleVersion($this->coreVersion, $this->pluginContractsVersion) === null) {
                continue;
            }

            $plugins[] = [
                'id' => (string) $plugin->id,
                'name' => $plugin->manifest->name,
                'type' => $plugin->manifest->type,
            ];
        }

        $path = $input->getOption('output');
        \assert(\is_string($path));
        $filesystem->dumpFile($path, $this->render($plugins));
        $output->writeln(\sprintf('Installer plugin list: %d plugin(s) written to %s', \count($plugins), $path));

        return Command::SUCCESS;
    }

    /**
     * @param list<array{id: string, name: string, type: PluginType}> $plugins
     */
    private function render(array $plugins): string
    {
        $out = <<<'NSH'
; GENERATED by "bin/console app:installer:plugins" at build time - DO NOT EDIT.
;
; AnimeDb package.
;
; @author    Peter Gribanov <info@peter-gribanov.ru>
; @copyright Copyright (c) 2026, Peter Gribanov
; @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
;
; This program is free software: you can redistribute it and/or modify
; it under the terms of the GNU General Public License as published by
; the Free Software Foundation, either version 3 of the License, or
; (at your option) any later version.
;
; This program is distributed in the hope that it will be useful,
; but WITHOUT ANY WARRANTY; without even the implied warranty of
; MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
; GNU General Public License for more details.
;
; You should have received a copy of the GNU General Public License
; along with this program. If not, see <https://www.gnu.org/licenses/>.

NSH;

        if ($plugins === []) {
            return $out."\n; No compatible plugins: the plugin selection page is not declared.\n";
        }

        $out .= "\n!define ANIMEDB_PLUGINS_PAGE\n!define ANIMEDB_PLUGINS_COUNT ".\count($plugins)."\n\n";

        foreach ($plugins as $i => $_) {
            $out .= \sprintf("Var AnimedbPlugin%d\nVar AnimedbPluginSelected%d\n", $i, $i);
        }

        $out .= "\n!macro animedbPluginsCreateCheckboxes\n";
        foreach ($plugins as $i => $plugin) {
            $out .= \sprintf(
                "  \${NSD_CreateCheckbox} 0 %du 100%% 12u \"%s\"\n  Pop \$AnimedbPlugin%d\n",
                $i * 14,
                $this->escape($plugin['name']),
                $i,
            );
            if ($this->checkedByDefault($plugin['type'])) {
                $out .= \sprintf("  \${NSD_Check} \$AnimedbPlugin%d\n", $i);
            }
        }
        $out .= "!macroend\n\n!macro animedbPluginsReadSelection\n  StrCpy \$AnimedbPluginsJson \"\"\n";
        foreach ($plugins as $i => $plugin) {
            $out .= \sprintf(
                "  \${NSD_GetState} \$AnimedbPlugin%1\$d \$AnimedbPluginSelected%1\$d\n"
                ."  \${If} \$AnimedbPluginSelected%1\$d == \${BST_CHECKED}\n"
                ."    \${If} \$AnimedbPluginsJson != \"\"\n      StrCpy \$AnimedbPluginsJson \"\$AnimedbPluginsJson,\"\n    \${EndIf}\n"
                ."    StrCpy \$AnimedbPluginsJson \"\$AnimedbPluginsJson\$\\\"%2\$s\$\\\"\"\n"
                ."  \${EndIf}\n",
                $i,
                $this->escape($plugin['id']),
            );
        }

        return $out."!macroend\n";
    }

    private function checkedByDefault(PluginType $type): bool
    {
        return match ($type) {
            PluginType::Local, PluginType::Translation => true,
            PluginType::Integration => false,
        };
    }

    private function escape(string $value): string
    {
        return str_replace(['$', '"', "\r", "\n", '&'], ['$$', '$\\"', '', ' ', '&&'], $value);
    }
}
