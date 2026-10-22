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

const crypto = require('node:crypto');

jest.mock('electron-updater/out/providers/Provider', () => {
    const actual = jest.requireActual('electron-updater/out/providers/Provider');
    return { ...actual, parseUpdateInfo: jest.fn(actual.parseUpdateInfo) };
});

const providerModule = require('electron-updater/out/providers/Provider');
const { SignedUpdateProvider, SOURCES } = require('../../native/updater/provider');

const pair = crypto.generateKeyPairSync('ed25519');
const rawKey = pair.publicKey.export({ format: 'der', type: 'spki' }).subarray(-32);
const sign = (text) => crypto.sign(null, Buffer.from(text), pair.privateKey).toString('base64');

const yml = (version) => `version: ${version}\nfiles:\n  - url: AnimeDB-Setup-${version}.exe\n    sha512: abc\n    size: 1\npath: AnimeDB-Setup-${version}.exe\nsha512: abc\n`;

/** feeds: { github: {yml, sig}|Error, mr01: ... }, keyed by host. */
function setup(feeds) {
    const logger = { info: jest.fn(), warn: jest.fn(), error: jest.fn(), debug: jest.fn() };
    const executor = {
        downloadToBuffer: jest.fn(async (url) => {
            const feed = feeds[url.hostname === 'github.com' ? 'github' : 'mr01'];
            if (feed instanceof Error) throw feed;
            return Buffer.from(url.pathname.endsWith('.sig') ? feed.sig : feed.yml);
        }),
    };
    const provider = new SignedUpdateProvider({}, { logger }, { executor }, { keys: [rawKey], timeoutMs: 1000 });
    return { provider, logger, executor };
}

const good = (version) => ({ yml: yml(version), sig: sign(yml(version)) });

beforeEach(() => providerModule.parseUpdateInfo.mockClear());

describe('SignedUpdateProvider', () => {
    test('uses the hard-coded GitHub and mr01 sources', () => {
        expect(SOURCES.map(s => s.name)).toEqual(['github', 'mr01']);
        expect(SOURCES[0].feedUrl).toBe('https://github.com/anime-db/anime-db-desktop/releases/latest/download/');
        expect(SOURCES[0].filesUrl('1.2.3')).toBe('https://github.com/anime-db/anime-db-desktop/releases/download/v1.2.3/');
        expect(SOURCES[1].feedUrl).toBe('https://mr01.anime-db.org/app/');
        expect(SOURCES[1].filesUrl('1.2.3')).toBe('https://mr01.anime-db.org/app/1.2.3/');
    });

    test('latest.yml with a bad signature never reaches the YAML parser', async () => {
        const bad = { yml: yml('9.9.9'), sig: sign('something else') };
        const s = setup({ github: bad, mr01: bad });
        await expect(s.provider.getLatestVersion()).rejects.toThrow(/verified/);
        expect(providerModule.parseUpdateInfo).not.toHaveBeenCalled();
    });

    test('picks the highest version among verified sources', async () => {
        const s = setup({ github: good('1.0.0'), mr01: good('1.1.0') });
        const info = await s.provider.getLatestVersion();
        expect(info.version).toBe('1.1.0');
        expect(s.provider.resolveFiles(info)[0].url.href).toBe('https://mr01.anime-db.org/app/1.1.0/AnimeDB-Setup-1.1.0.exe');
    });

    test('prefers GitHub when versions are equal', async () => {
        const s = setup({ github: good('1.0.0'), mr01: good('1.0.0') });
        const info = await s.provider.getLatestVersion();
        expect(s.provider.resolveFiles(info)[0].url.href)
            .toBe('https://github.com/anime-db/anime-db-desktop/releases/download/v1.0.0/AnimeDB-Setup-1.0.0.exe');
    });

    test.each([
        ['unreachable', new Error('ECONNREFUSED')],
        ['badly signed', { yml: yml('9.0.0'), sig: sign('other') }],
    ])('uses the valid source and warns when the other is %s', async (_n, broken) => {
        const s = setup({ github: broken, mr01: good('1.0.0') });
        const info = await s.provider.getLatestVersion();
        expect(info.version).toBe('1.0.0');
        expect(s.logger.warn).toHaveBeenCalledWith(expect.stringContaining('github'));
        expect(s.logger.error).not.toHaveBeenCalled();
    });

    test('fails closed when no source is verified', async () => {
        const s = setup({ github: new Error('down'), mr01: { yml: yml('1.0.0'), sig: 'AAAA' } });
        await expect(s.provider.getLatestVersion()).rejects.toMatchObject({ code: 'ERR_UPDATER_NO_VERIFIED_SOURCE' });
        expect(s.logger.warn).toHaveBeenCalledTimes(2);
    });

    test('resolves files and blockmaps from the source of each updateInfo', async () => {
        const feeds = { github: good('1.0.0'), mr01: good('1.1.0') };
        const s = setup(feeds);
        const first = await s.provider.getLatestVersion();
        feeds.mr01 = new Error('down');
        feeds.github = good('1.2.0');
        const second = await s.provider.getLatestVersion();

        const a = s.provider.resolveFiles(first)[0].url;
        const b = s.provider.resolveFiles(second)[0].url;
        expect(a.hostname).toBe('mr01.anime-db.org');
        expect(b.hostname).toBe('github.com');
        const [oldMap, newMap] = s.provider.getBlockMapFiles(a, '1.0.0', '1.1.0');
        expect(newMap.href).toBe(`${a.href}.blockmap`);
        expect(oldMap.href).toBe('https://mr01.anime-db.org/app/1.0.0/AnimeDB-Setup-1.0.0.exe.blockmap');
    });

    test('refuses to resolve files for update info of unknown origin', () => {
        const s = setup({});
        expect(() => s.provider.resolveFiles({ version: '1.0.0', files: [] })).toThrow();
    });
});
