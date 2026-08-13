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

jest.mock('../../native/paths', () => ({
    getQbittorrentProfileDir:  jest.fn(() => '/fake/qbittorrent'),
    getQbittorrentConfigPath:  jest.fn(() => '/fake/qbittorrent/qBittorrent/config/qBittorrent.ini'),
    getRuntimeDir:             jest.fn(() => '/fake/var'),
}));
jest.mock('../../native/config', () => ({
    getProxySettings: jest.fn(),
}));
jest.mock('../../native/supervisor/logrotate', () => ({
    pruneOldLogs:  jest.fn(),
    openLogStream: jest.fn(),
}));
jest.mock('../../native/supervisor/healthcheck', () => ({
    waitForHealth: jest.fn(),
}));

const fs = require('fs');
const {
    upsertIniValue,
    isSocks5Proxy,
    seedConfig,
    WEBUI_PORT,
    BT_PORT,
} = require('../../native/supervisor/qbittorrent');

describe('upsertIniValue', () => {
    test('appends a new section when the file is empty', () => {
        const result = upsertIniValue('', 'WebUI', 'Port', '18080');
        expect(result).toBe('[WebUI]\nPort=18080');
    });

    test('inserts a new key into an existing section without touching other keys', () => {
        const original = '[WebUI]\nPort=8080\n\n[Network]\nProxy\\Type=0';
        const result = upsertIniValue(original, 'WebUI', 'Address', '"127.0.0.1"');
        expect(result).toBe('[WebUI]\nPort=8080\nAddress="127.0.0.1"\n\n[Network]\nProxy\\Type=0');
    });

    test('replaces an existing key in place, preserving surrounding lines', () => {
        const original = '[WebUI]\nPort=8080\nAddress="*"\n\n[Network]\nProxy\\Type=0';
        const result = upsertIniValue(original, 'WebUI', 'Address', '"127.0.0.1"');
        expect(result).toBe('[WebUI]\nPort=8080\nAddress="127.0.0.1"\n\n[Network]\nProxy\\Type=0');
    });

    test('creates a brand-new section after existing ones', () => {
        const original = '[WebUI]\nPort=8080';
        const result = upsertIniValue(original, 'BitTorrent', 'Session\\Port', '51413');
        expect(result).toBe('[WebUI]\nPort=8080\n\n[BitTorrent]\nSession\\Port=51413');
    });
});

describe('isSocks5Proxy', () => {
    test('false when proxy is null', () => {
        expect(isSocks5Proxy(null)).toBe(false);
    });

    test('false for mode "none"', () => {
        expect(isSocks5Proxy({ mode: 'none', protocol: 'socks5', host: 'h', port: 1080 })).toBe(false);
    });

    test('false for protocol "http"', () => {
        expect(isSocks5Proxy({ mode: 'manual', protocol: 'http', host: 'h', port: 1080 })).toBe(false);
    });

    test('false when host or port is missing', () => {
        expect(isSocks5Proxy({ mode: 'manual', protocol: 'socks5', host: '', port: 1080 })).toBe(false);
        expect(isSocks5Proxy({ mode: 'manual', protocol: 'socks5', host: 'h', port: null })).toBe(false);
    });

    test('true for a fully-specified manual SOCKS5 proxy', () => {
        expect(isSocks5Proxy({ mode: 'manual', protocol: 'socks5', host: 'h', port: 1080 })).toBe(true);
    });
});

describe('seedConfig', () => {
    let existsSyncSpy;
    let readFileSyncSpy;
    let writeFileSyncSpy;
    let mkdirSyncSpy;

    beforeEach(() => {
        existsSyncSpy    = jest.spyOn(fs, 'existsSync').mockReturnValue(false);
        readFileSyncSpy   = jest.spyOn(fs, 'readFileSync');
        writeFileSyncSpy  = jest.spyOn(fs, 'writeFileSync').mockImplementation(() => {});
        mkdirSyncSpy      = jest.spyOn(fs, 'mkdirSync').mockImplementation(() => {});
    });

    afterEach(() => {
        jest.restoreAllMocks();
    });

    test('seeds pinned ports, loopback-only WebUI and DHT/PEX with no proxy configured', () => {
        seedConfig(null);

        expect(mkdirSyncSpy).toHaveBeenCalledWith('/fake/qbittorrent/qBittorrent/config', { recursive: true });

        const written = writeFileSyncSpy.mock.calls[0][1];
        expect(written).toContain(`Port=${WEBUI_PORT}`);
        expect(written).toContain('Address="127.0.0.1"');
        expect(written).toContain('LocalHostAuth=false');
        expect(written).toContain(`Session\\Port=${BT_PORT}`);
        expect(written).toContain('Session\\DHTEnabled=true');
        expect(written).toContain('Session\\PeXEnabled=true');
        expect(written).toContain('Proxy\\Type=None');
        expect(written).toContain('HostHeaderValidation=false');
        expect(written).not.toContain('Proxy\\IP=');
    });

    test('seeds SOCKS5 proxy settings (with credentials) before the process would ever spawn', () => {
        seedConfig({
            mode: 'manual',
            protocol: 'socks5',
            host: 'proxy.example.com',
            port: 1080,
            username: 'alice',
            password: 'p@ss"word',
        });

        const written = writeFileSyncSpy.mock.calls[0][1];
        expect(written).toContain('Proxy\\Type=SOCKS5');
        expect(written).toContain('Proxy\\IP="proxy.example.com"');
        expect(written).toContain('Proxy\\Port=1080');
        expect(written).toContain('Proxy\\HostnameLookupEnabled=true');
        expect(written).toContain('Proxy\\Profiles\\BitTorrent=true');
        expect(written).toContain('Session\\ProxyPeerConnections=true');
        expect(written).toContain('Proxy\\AuthEnabled=true');
        expect(written).toContain('Proxy\\Username="alice"');
        expect(written).toContain('Proxy\\Password="p@ss\\"word"');
    });

    test('preserves unrelated existing content already written by qbittorrent-nox itself', () => {
        existsSyncSpy.mockReturnValue(true);
        readFileSyncSpy.mockReturnValue('[Downloads]\nSavePath="D:\\\\Torrents"\n\n[WebUI]\nPort=8080');

        seedConfig(null);

        const written = writeFileSyncSpy.mock.calls[0][1];
        expect(written).toContain('[Downloads]');
        expect(written).toContain('SavePath="D:\\\\Torrents"');
        expect(written).toContain(`Port=${WEBUI_PORT}`);
    });
});
