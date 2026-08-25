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

'use strict';

const path = require('path');
const { pathToFileURL } = require('url');
const { app, protocol, net } = require('electron');
const { getMediaDir } = require('../paths');

const SCHEME = 'app-media';
const RESOURCE_TYPE = 'anime';

const MIME_TYPES = {
    '.webp': 'image/webp',
};

// Должно выполниться синхронно при загрузке модуля, до app.ready.
protocol.registerSchemesAsPrivileged([
    {
        scheme:     SCHEME,
        privileges: {
            standard:        true,
            secure:          true,
            supportFetchAPI: true,
            corsEnabled:     true,
            stream:          true,
        },
    },
]);

/**
 * Разбирает app-media://anime/{id}/{filename} на id аниме и имя файла.
 *
 * @param {URL} url
 * @returns {{ animeId: string, filename: string }}
 */
function parseAppMediaUrl(url) {
    if (url.hostname !== RESOURCE_TYPE) {
        throw new Error(`Unsupported app-media resource type: "${url.hostname}"`);
    }

    const segments = url.pathname.split('/').filter(Boolean).map(decodeURIComponent);
    if (segments.length !== 2) {
        throw new Error(`Malformed app-media URL: "${url.href}"`);
    }

    const [animeId, filename] = segments;
    if (!/^[1-9]\d*$/.test(animeId)) {
        throw new Error(`Invalid anime id: "${animeId}"`);
    }
    if (filename === '' || filename.includes('/') || filename.includes('\\')) {
        throw new Error(`Invalid media filename: "${filename}"`);
    }

    return { animeId, filename };
}

/**
 * Резолвит {id}/{filename} в абсолютный путь внутри %AppData%/media/.
 * Бросает, если итоговый путь выходит за пределы этой директории.
 *
 * @param {string} animeId
 * @param {string} filename
 * @returns {string}
 */
function resolveAppDataMediaPath(animeId, filename) {
    const mediaDir = path.resolve(getMediaDir());
    const filePath  = path.resolve(mediaDir, animeId, filename);
    const boundary  = mediaDir + path.sep;

    if (filePath !== mediaDir && !filePath.startsWith(boundary)) {
        throw new Error(`Path traversal blocked: "${animeId}/${filename}"`);
    }

    return filePath;
}

/**
 * Определяет MIME-тип по расширению файла, без сниффинга содержимого.
 *
 * @param {string} filename
 * @returns {string}
 */
function getMimeType(filename) {
    return MIME_TYPES[path.extname(filename).toLowerCase()] || 'application/octet-stream';
}

app.whenReady().then(() => {
    protocol.handle(SCHEME, async (request) => {
        let filePath;
        try {
            const { animeId, filename } = parseAppMediaUrl(new URL(request.url));
            filePath = resolveAppDataMediaPath(animeId, filename);
        } catch (err) {
            return new Response(err.message, { status: 400 });
        }

        let fileResponse;
        try {
            fileResponse = await net.fetch(pathToFileURL(filePath).toString());
        } catch {
            return new Response(null, { status: 404 });
        }

        const headers = new Headers(fileResponse.headers);
        headers.set('Content-Type', getMimeType(filePath));
        headers.set('Cache-Control', 'public, max-age=31536000, immutable');

        return new Response(fileResponse.body, { status: fileResponse.status, headers });
    });
});

module.exports = { parseAppMediaUrl, resolveAppDataMediaPath, getMimeType };
