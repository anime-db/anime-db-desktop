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

jest.mock('electron', () => ({
    app:      { whenReady: jest.fn(() => Promise.resolve()) },
    protocol: { registerSchemesAsPrivileged: jest.fn(), handle: jest.fn() },
    net:      { fetch: jest.fn() },
}));

jest.mock('../../../native/paths', () => ({
    getMediaDir: jest.fn(() => '/appdata/AnimeDB/media'),
}));

const path = require('path');
const { protocol } = require('electron');
const {
    parseAppMediaUrl,
    resolveAppDataMediaPath,
    getMimeType,
} = require('../../../native/protocols/app-media');

const MEDIA_DIR = path.resolve('/appdata/AnimeDB/media');

test('registers app-media as a privileged scheme on module load', () => {
    expect(protocol.registerSchemesAsPrivileged).toHaveBeenCalledWith([
        expect.objectContaining({ scheme: 'app-media' }),
    ]);
});

describe('parseAppMediaUrl', () => {
    test('extracts animeId and filename from a well-formed URL', () => {
        const url = new URL('app-media://anime/42/cover_1720273812345.webp');
        expect(parseAppMediaUrl(url)).toEqual({ animeId: '42', filename: 'cover_1720273812345.webp' });
    });

    test('rejects a resource type other than "anime"', () => {
        const url = new URL('app-media://studio/42/logo.webp');
        expect(() => parseAppMediaUrl(url)).toThrow(/Unsupported app-media resource type/);
    });

    test('rejects a non-numeric anime id', () => {
        const url = new URL('app-media://anime/abc/cover.webp');
        expect(() => parseAppMediaUrl(url)).toThrow(/Invalid anime id/);
    });

    test('rejects a URL missing the filename segment', () => {
        const url = new URL('app-media://anime/42');
        expect(() => parseAppMediaUrl(url)).toThrow(/Malformed app-media URL/);
    });

    test('rejects a filename that decodes to a path with a separator', () => {
        const url = new URL('app-media://anime/42/..%2f..%2fetc%2fpasswd');
        expect(() => parseAppMediaUrl(url)).toThrow(/Invalid media filename/);
    });
});

describe('resolveAppDataMediaPath', () => {
    test('resolves id/filename inside %AppData%/media/', () => {
        expect(resolveAppDataMediaPath('42', 'cover_1720273812345.webp'))
            .toBe(path.join(MEDIA_DIR, '42', 'cover_1720273812345.webp'));
    });

    test('blocks traversal via ".." in the anime id', () => {
        expect(() => resolveAppDataMediaPath('../../etc', 'passwd'))
            .toThrow(/Path traversal blocked/);
    });

    test('blocks traversal via ".." in the filename', () => {
        expect(() => resolveAppDataMediaPath('42', '../../../etc/passwd'))
            .toThrow(/Path traversal blocked/);
    });

    test('blocks an absolute path smuggled in as the filename', () => {
        expect(() => resolveAppDataMediaPath('42', '/etc/passwd'))
            .toThrow(/Path traversal blocked/);
    });
});

describe('getMimeType', () => {
    test.each([
        ['cover.webp', 'image/webp'],
        ['cover.JPG', 'image/jpeg'],
        ['cover.jpeg', 'image/jpeg'],
        ['cover.png', 'image/png'],
        ['cover.gif', 'image/gif'],
        ['cover.bin', 'application/octet-stream'],
    ])('maps %s to %s', (filename, expected) => {
        expect(getMimeType(filename)).toBe(expected);
    });
});
