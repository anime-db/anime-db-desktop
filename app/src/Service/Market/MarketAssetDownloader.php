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

namespace App\Service\Market;

use App\Entity\ValueObject\PluginId;
use App\Service\Market\Exception\PluginAssetDownloadException;
use App\Service\Market\Exception\UnknownPluginVersionException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads a plugin's `plugin.zip` for market installation, deriving its URL from the trusted
 * registry's `asset_mirrors` ({@see PluginAssetUrlResolver}) and trying each mirror in order
 * until one serves bytes whose sha256 matches the registry's pinned checksum for that
 * plugin/version. The registry's signature (already verified before this class ever sees a
 * {@see PluginRegistry}) is what makes the expected sha256 trustworthy — the mirror serving the
 * bytes is not: any mirror, including the first, may be down, slow, or (accidentally) serving a
 * truncated/stale copy, so a failure or a checksum mismatch just moves on to the next mirror
 * rather than aborting immediately.
 *
 * Resolving *which* version to install (compatibility with the current core version, picking the
 * newest compatible one) is the market storefront's job (issue #220), not this class's — it only
 * ever downloads a version the caller already decided on.
 */
final class MarketAssetDownloader
{
    private const string ASSET_FILE_NAME = 'plugin.zip';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PluginAssetUrlResolver $urlResolver = new PluginAssetUrlResolver(),
    ) {
    }

    /**
     * @return string path to a temporary file holding the downloaded, sha256-verified archive —
     *                the caller is responsible for removing it once done with it (e.g. after
     *                handing it to {@see \App\Service\Plugin\ZipPluginInstaller::install()})
     *
     * @throws UnknownPluginVersionException if the registry has no sha256 pinned for this plugin/version
     * @throws PluginAssetDownloadException  if every mirror failed to serve a matching archive
     */
    public function downloadPluginZip(PluginRegistry $registry, PluginId $pluginId, string $version): string
    {
        $expectedSha256 = $registry->findVersionSha256($pluginId, $version);
        if ($expectedSha256 === null) {
            throw new UnknownPluginVersionException($pluginId, $version);
        }

        $failuresByUrl = [];

        foreach ($registry->assetMirrors as $mirrorUrlTemplate) {
            $url = $this->urlResolver->resolve($mirrorUrlTemplate, $pluginId, $version, self::ASSET_FILE_NAME);

            try {
                $localPath = $this->downloadToTempFile($url);
            } catch (\Throwable $exception) {
                $failuresByUrl[$url] = $exception->getMessage();
                continue;
            }

            if (hash_equals($expectedSha256, hash_file('sha256', $localPath) ?: '')) {
                return $localPath;
            }

            unlink($localPath);
            $failuresByUrl[$url] = 'sha256 checksum mismatch';
        }

        throw new PluginAssetDownloadException($pluginId, $version, $failuresByUrl);
    }

    private function downloadToTempFile(string $url): string
    {
        $content = $this->httpClient->request('GET', $url)->getContent();

        $tmpPath = tempnam(sys_get_temp_dir(), 'anime-db-plugin-asset-');
        if ($tmpPath === false) {
            throw new \RuntimeException('Unable to create a temporary file for the downloaded asset.');
        }

        if (file_put_contents($tmpPath, $content) === false) {
            @unlink($tmpPath);

            throw new \RuntimeException(\sprintf('Unable to write downloaded asset to "%s".', $tmpPath));
        }

        return $tmpPath;
    }
}
