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

use App\Service\Catalog\SourceUrlNormalizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The ordered link rules of the enabled plugins that declare `link_rules`.
 *
 * Plugins are ordered by id descending (the larger id wins, the same convention as translations),
 * rules inside a file keep their order. When two plugins carry the same expression the first one
 * (larger id) is kept. A file that cannot be read, parsed or validated is skipped as a whole with
 * a warning; the other plugins' rules still apply.
 *
 * Plugins without code are not announced to the workers with `workers.reload`, so a long-lived
 * worker would keep a stale rule set. The set is therefore kept in memory by {@see self::fingerprint()}
 * and re-read as soon as the fingerprint changes.
 */
final class LinkRulesProvider
{
    private ?string $cachedFingerprint = null;

    /** @var array<string, string> */
    private array $cachedRules = [];

    public function __construct(
        private readonly InstalledPluginsRegistry $registry,
        private readonly LinkRulesFileParser $parser = new LinkRulesFileParser(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return array<string, string> expression => replacement, in order of application
     */
    public function rules(): array
    {
        return $this->load()[1];
    }

    /**
     * The rules and their fingerprint read together, once: for work over many rows, which must not
     * re-read the rule files per row.
     */
    public function snapshot(): LinkRulesSnapshot
    {
        [$fingerprint, $rules] = $this->load();

        return new LinkRulesSnapshot($fingerprint, $rules);
    }

    /**
     * Hash of the ids and versions of the plugins with rules, the content of their rule files and
     * {@see SourceUrlNormalizer::VERSION}.
     */
    public function fingerprint(): string
    {
        return $this->load()[0];
    }

    /**
     * @return array{string, array<string, string>}
     */
    private function load(): array
    {
        $sources = $this->sources();
        $fingerprint = hash('sha256', json_encode([SourceUrlNormalizer::VERSION, $sources], \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE));

        if ($this->cachedFingerprint !== $fingerprint) {
            $this->cachedRules = $this->parseSources($sources);
            $this->cachedFingerprint = $fingerprint;
        }

        return [$fingerprint, $this->cachedRules];
    }

    /**
     * @return list<array{id: string, version: string, contents: string|null}> by plugin id descending
     */
    private function sources(): array
    {
        $plugins = array_filter(
            $this->registry->enabled(),
            static fn (InstalledPlugin $plugin): bool => $plugin->manifest->linkRules,
        );
        usort($plugins, static fn (InstalledPlugin $a, InstalledPlugin $b): int => strcmp((string) $b->id, (string) $a->id));

        $sources = [];
        foreach ($plugins as $plugin) {
            $path = $plugin->installPath.\DIRECTORY_SEPARATOR.LinkRulesFileParser::FILE_NAME;
            $contents = is_file($path) ? @file_get_contents($path) : false;
            $sources[] = [
                'id' => (string) $plugin->id,
                'version' => $plugin->manifest->version,
                'contents' => $contents === false ? null : $contents,
            ];
        }

        return $sources;
    }

    /**
     * @param list<array{id: string, version: string, contents: string|null}> $sources
     *
     * @return array<string, string>
     */
    private function parseSources(array $sources): array
    {
        $rules = [];
        foreach ($sources as $source) {
            if ($source['contents'] === null) {
                $this->logger->warning('Link rules file of plugin {pluginId} is missing or unreadable; skipped.', [
                    'pluginId' => $source['id'],
                ]);

                continue;
            }

            try {
                $fileRules = $this->parser->parse($source['contents']);
            } catch (\InvalidArgumentException $exception) {
                $this->logger->warning('Link rules file of plugin {pluginId} is invalid and not applied: {message}', [
                    'pluginId' => $source['id'],
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            foreach ($fileRules as $expression => $replacement) {
                $rules[$expression] ??= $replacement;
            }
        }

        return $rules;
    }
}
