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
    buildElevatedNetshCommand,
    applyIncomingConnections,
} = require('../../native/firewall');

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

describe('buildAddRuleArgs', () => {
    test('builds an inbound allow rule for the given protocol on the BT port', () => {
        expect(buildAddRuleArgs('TCP', RULE_NAMES.TCP)).toEqual([
            'advfirewall', 'firewall', 'add', 'rule',
            'name=AnimeDB qBittorrent (TCP)',
            'dir=in',
            'action=allow',
            'protocol=TCP',
            `localport=${BT_PORT}`,
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

describe('buildElevatedNetshCommand', () => {
    test('quotes the rule name so it survives Start-Process -ArgumentList joining args with spaces', () => {
        const command = buildElevatedNetshCommand(buildAddRuleArgs('TCP', RULE_NAMES.TCP));

        expect(command).toBe(
            'Start-Process -FilePath \'netsh\' -ArgumentList '
            + '\'advfirewall firewall add rule name="AnimeDB qBittorrent (TCP)" dir=in action=allow protocol=TCP localport=51413\' '
            + '-Verb RunAs -Wait -WindowStyle Hidden',
        );
    });

    test('escapes a single quote in an argument for PowerShell', () => {
        const command = buildElevatedNetshCommand(['advfirewall', "o'brien"]);

        expect(command).toContain("o''brien");
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

    test('spawns one elevated powershell call per protocol on Windows', async () => {
        Object.defineProperty(process, 'platform', { value: 'win32' });
        const fakeChild = { on: jest.fn((event, cb) => { if (event === 'exit') cb(0); }) };
        spawn.mockReturnValue(fakeChild);

        await applyIncomingConnections(true);

        expect(spawn).toHaveBeenCalledTimes(2);
        expect(spawn).toHaveBeenNthCalledWith(
            1,
            'powershell.exe',
            expect.arrayContaining(['-Command', expect.stringContaining('protocol=TCP')]),
            expect.any(Object),
        );
        expect(spawn).toHaveBeenNthCalledWith(
            2,
            'powershell.exe',
            expect.arrayContaining(['-Command', expect.stringContaining('protocol=UDP')]),
            expect.any(Object),
        );
    });

    test('builds delete-rule commands when disabling', async () => {
        Object.defineProperty(process, 'platform', { value: 'win32' });
        const fakeChild = { on: jest.fn((event, cb) => { if (event === 'exit') cb(0); }) };
        spawn.mockReturnValue(fakeChild);

        await applyIncomingConnections(false);

        expect(spawn).toHaveBeenNthCalledWith(
            1,
            'powershell.exe',
            expect.arrayContaining(['-Command', expect.stringContaining('delete')]),
            expect.any(Object),
        );
    });

    test('rejects when the elevated netsh call exits non-zero (e.g. UAC declined)', async () => {
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
