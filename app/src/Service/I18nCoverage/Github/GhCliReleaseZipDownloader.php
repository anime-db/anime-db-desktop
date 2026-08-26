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

namespace App\Service\I18nCoverage\Github;

/**
 * Real {@see GhReleaseZipDownloader}. Release tags in the plugins monorepo are shaped
 * `<plugin-id>/<version>` (mirrors `anime-db-plugins`' own `release.yml`); "latest" is whichever
 * matching tag `gh release list` reports the most recent `publishedAt` for, not the highest
 * semver — the two agree in practice (releases are cut in version order) and avoiding a second
 * version-ordering opinion here keeps this in lockstep with what `gh release view --json` itself
 * considers current.
 */
final class GhCliReleaseZipDownloader implements GhReleaseZipDownloader
{
    private const string RELEASE_ASSET_FILE_NAME = 'plugin.zip';

    public function __construct(
        private readonly string $repo,
        private readonly GhCommand $gh = new GhCommand(),
    ) {
    }

    public function downloadLatestReleaseZip(string $pluginId): string
    {
        $tag = $this->latestReleaseTag($pluginId);

        $dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'i18n-coverage-'.bin2hex(random_bytes(8));
        if (!mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Unable to create temp directory "%s".', $dir));
        }

        $this->gh->run([
            'release', 'download', $tag,
            '--repo', $this->repo,
            '--dir', $dir,
            '--clobber',
            '--pattern', self::RELEASE_ASSET_FILE_NAME,
        ]);

        $zipPath = $dir.\DIRECTORY_SEPARATOR.self::RELEASE_ASSET_FILE_NAME;
        if (!is_file($zipPath)) {
            throw new \RuntimeException(\sprintf('Release "%s" has no "%s" asset.', $tag, self::RELEASE_ASSET_FILE_NAME));
        }

        return $zipPath;
    }

    private function latestReleaseTag(string $pluginId): string
    {
        $json = $this->gh->run([
            'release', 'list',
            '--repo', $this->repo,
            '--limit', '1000',
            '--json', 'tagName,publishedAt',
        ]);

        /** @var list<array{tagName: string, publishedAt: string}> $releases */
        $releases = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        $prefix = $pluginId.'/';
        $matching = array_values(array_filter(
            $releases,
            static fn (array $release): bool => str_starts_with($release['tagName'], $prefix),
        ));

        if ($matching === []) {
            throw new \RuntimeException(\sprintf('No release found for plugin "%s" in "%s".', $pluginId, $this->repo));
        }

        usort($matching, static fn (array $a, array $b): int => $b['publishedAt'] <=> $a['publishedAt']);

        return $matching[0]['tagName'];
    }
}
