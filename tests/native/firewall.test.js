/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

'use strict';

const fs   = require('fs');
const path = require('path');

jest.mock('child_process', () => ({ spawn: jest.fn() }));

const { spawn } = require('child_process');
const {
    FIREWALL_RULE_CHANGED_EVENT,
    BT_PORT,
    RULE_NAMES,
    buildAddRuleArgs,
    buildDeleteRuleArgs,
    buildNetshArgs,
    buildNetshCommandLine,
    buildElevatedNetshCommand,
    applyIncomingConnections,
} = require('../../native/firewall');

/**
 * Decodes the base64 UTF-16LE `-EncodedCommand` payload embedded in a buildElevatedNetshCommand()
 * result back into the plain netsh script, so tests can assert on its content.
 *
 * @param {string} command
 * @returns {string}
 */
function decodeEncodedCommand(command) {
    const matched = command.match(/-EncodedCommand ([A-Za-z0-9+/=]+)/);
    expect(matched).not.toBeNull();

    return Buffer.from(matched[1], 'base64').toString('utf16le');
}

beforeEach(() => {
    jest.clearAllMocks();
});

describe('BT_PORT', () => {
    test('matches native/supervisor/qbittorrent.js\'s BT_PORT literal', () => {
        const source = fs.readFileSync(path.join(__dirname, '../../native/supervisor/qbittorrent.js'), 'utf8');
        const matched = source.match(/const BT_PORT\s*=\s*(\d+);/);

        expect(matched).not.toBeNull();
        expect(BT_PORT).toBe(Number(matched[1]));
    });
});

describe('program= path', () => {
    test('resolves to the same qbittorrent-nox.exe path as native/supervisor/qbittorrent.js\'s BINARY', () => {
        const supervisorDir = path.join(__dirname, '../../native/supervisor');
        const source = fs.readFileSync(path.join(supervisorDir, 'qbittorrent.js'), 'utf8');
        const matched = source.match(/const BINARY = path\.join\(__dirname, ((?:'[^']*',?\s*)+)\);/);

        expect(matched).not.toBeNull();
        const segments = matched[1].match(/'([^']*)'/g).map((quoted) => quoted.slice(1, -1));
        const expectedBinary = path.join(supervisorDir, ...segments);

        const programArg = buildAddRuleArgs('TCP', RULE_NAMES.TCP).find((arg) => arg.startsWith('program='));

        expect(programArg).toBe(`program=${expectedBinary}`);
    });
});

describe('buildAddRuleArgs', () => {
    test('builds an inbound allow rule for the given protocol on the BT port, scoped to the qbittorrent-nox program and the private profile', () => {
        const args = buildAddRuleArgs('TCP', RULE_NAMES.TCP);

        expect(args).toEqual([
            'advfirewall', 'firewall', 'add', 'rule',
            'name=AnimeDB qBittorrent (TCP)',
            'dir=in',
            'action=allow',
            expect.stringMatching(/^program=.*qbittorrent-nox\.exe$/),
            'protocol=TCP',
            `localport=${BT_PORT}`,
            'profile=private',
        ]);
    });

    test('uses the UDP rule name and protocol for UDP', () => {
        const args = buildAddRuleArgs('UDP', RULE_NAMES.UDP);

        expect(args).toContain('name=AnimeDB qBittorrent (UDP)');
        expect(args).toContain('protocol=UDP');
    });
});

describe('buildDeleteRuleArgs', () => {
    test('deletes the rule by name only', () => {
        expect(buildDeleteRuleArgs(RULE_NAMES.UDP)).toEqual([
            'advfirewall', 'firewall', 'delete', 'rule', 'name=AnimeDB qBittorrent (UDP)',
        ]);
    });
});

describe('buildNetshArgs', () => {
    test('enabled=true builds add-rule args', () => {
        expect(buildNetshArgs(true, 'TCP')).toEqual(buildAddRuleArgs('TCP', RULE_NAMES.TCP));
    });

    test('enabled=false builds delete-rule args', () => {
        expect(buildNetshArgs(false, 'UDP')).toEqual(buildDeleteRuleArgs(RULE_NAMES.UDP));
    });
});

describe('buildNetshCommandLine', () => {
    test('quotes name= and program= values so they survive being flattened into one command-line string', () => {
        const commandLine = buildNetshCommandLine(buildAddRuleArgs('TCP', RULE_NAMES.TCP));

        expect(commandLine).toContain('name="AnimeDB qBittorrent (TCP)"');
        expect(commandLine).toMatch(/program="[^"]*qbittorrent-nox\.exe"/);
        expect(commandLine).toContain('dir=in');
        expect(commandLine).toContain('localport=51413');
    });
});

describe('buildElevatedNetshCommand', () => {
    test('runs both TCP and UDP netsh calls inside a single elevated Start-Process (one UAC prompt)', () => {
        const command = buildElevatedNetshCommand(true);

        expect(command).toMatch(/^Start-Process -FilePath 'powershell\.exe' -ArgumentList '-NoProfile -NonInteractive -EncodedCommand [A-Za-z0-9+/=]+' -Verb RunAs -Wait -WindowStyle Hidden$/);

        const script = decodeEncodedCommand(command);

        expect(script).toContain('netsh advfirewall firewall add rule name="AnimeDB qBittorrent (TCP)"');
        expect(script).toContain('netsh advfirewall firewall add rule name="AnimeDB qBittorrent (UDP)"');
        expect(script.indexOf('protocol=TCP')).toBeLessThan(script.indexOf('protocol=UDP'));
    });

    test('builds delete-rule netsh calls for both protocols when disabling', () => {
        const script = decodeEncodedCommand(buildElevatedNetshCommand(false));

        expect(script).toContain('netsh advfirewall firewall delete rule name="AnimeDB qBittorrent (TCP)"');
        expect(script).toContain('netsh advfirewall firewall delete rule name="AnimeDB qBittorrent (UDP)"');
    });
});

describe('applyIncomingConnections', () => {
    const originalPlatform = process.platform;

    afterEach(() => {
        Object.defineProperty(process, 'platform', { value: originalPlatform });
    });

    test('does nothing on non-Windows platforms', async () => {
        Object.defineProperty(process, 'platform', { value: 'linux' });

        await applyIncomingConnections(true);

        expect(spawn).not.toHaveBeenCalled();
    });

    test('spawns exactly one elevated powershell call for both protocols on Windows', async () => {
        Object.defineProperty(process, 'platform', { value: 'win32' });
        const fakeChild = { on: jest.fn((event, cb) => { if (event === 'exit') cb(0); }) };
        spawn.mockReturnValue(fakeChild);

        await applyIncomingConnections(true);

        expect(spawn).toHaveBeenCalledTimes(1);

        const [, args] = spawn.mock.calls[0];
        const commandIndex = args.indexOf('-Command');
        const script = decodeEncodedCommand(args[commandIndex + 1]);

        expect(script).toContain('protocol=TCP');
        expect(script).toContain('protocol=UDP');
    });

    test('builds delete-rule commands when disabling', async () => {
        Object.defineProperty(process, 'platform', { value: 'win32' });
        const fakeChild = { on: jest.fn((event, cb) => { if (event === 'exit') cb(0); }) };
        spawn.mockReturnValue(fakeChild);

        await applyIncomingConnections(false);

        const [, args] = spawn.mock.calls[0];
        const commandIndex = args.indexOf('-Command');
        const script = decodeEncodedCommand(args[commandIndex + 1]);

        expect(script).toContain('delete');
    });

    test('rejects when the elevated call exits non-zero (e.g. UAC declined)', async () => {
        Object.defineProperty(process, 'platform', { value: 'win32' });
        const fakeChild = { on: jest.fn((event, cb) => { if (event === 'exit') cb(1); }) };
        spawn.mockReturnValue(fakeChild);

        await expect(applyIncomingConnections(true)).rejects.toThrow(/exited with code 1/);
    });
});

describe('FIREWALL_RULE_CHANGED_EVENT', () => {
    test('is a stable, non-empty event name', () => {
        expect(FIREWALL_RULE_CHANGED_EVENT).toBe('firewall.rule.changed');
    });
});
