/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 *
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

/**
 * Republish guard: a tag whose release already has a different latest.yml must not be published
 * again. A full rerun rebuilds the installer with another sha512; a rerun of a failed publish
 * produces identical bytes and passes.
 *
 * Usage:   node scripts/check-release-republish.js <tag> <path/to/latest.yml>
 * Network: GET https://github.com/anime-db/anime-db-desktop/releases/download/<tag>/latest.yml,
 *          no token (the repository is public), global fetch of Node 22
 * Exit:    0 no release yet (404) or identical bytes; 1 the release has different bytes;
 *          2 wrong usage, unreadable file or an unexpected response (cannot tell, so do not publish)
 *
 * Needs Node 22 only, no node_modules.
 */

const fs = require('node:fs');

const BASE_URL = 'https://github.com/anime-db/anime-db-desktop/releases/download';

async function check(tag, file, fetchImpl = globalThis.fetch) {
    const local = fs.readFileSync(file);
    const response = await fetchImpl(`${BASE_URL}/${encodeURIComponent(tag)}/latest.yml`, { cache: 'no-store' });
    if (response.status === 404) {
        console.log(`Release ${tag} does not exist yet: nothing to compare.`);
        return 0;
    }
    if (!response.ok) {
        console.error(`::error::unexpected HTTP ${response.status} for the latest.yml of ${tag}`);
        return 2;
    }
    const published = Buffer.from(await response.arrayBuffer());
    if (published.equals(local)) {
        console.log(`Release ${tag} already has an identical latest.yml: rerun of a publish.`);
        return 0;
    }
    console.error(`::error::release ${tag} already has a different latest.yml: the installer was rebuilt with another sha512, refusing to publish over it`);
    return 1;
}

async function main(argv = process.argv.slice(2), fetchImpl = globalThis.fetch) {
    if (argv.length !== 2) {
        console.error('Usage: check-release-republish.js <tag> <path/to/latest.yml>');
        return 2;
    }
    try {
        return await check(argv[0], argv[1], fetchImpl);
    } catch (e) {
        console.error(`::error::republish check failed: ${e.message}`);
        return 2;
    }
}

if (require.main === module) {
    main().then((code) => {
        process.exitCode = code;
    });
}

module.exports = { check, main, BASE_URL };
