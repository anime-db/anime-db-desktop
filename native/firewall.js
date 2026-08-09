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

const { spawn } = require('child_process');

/**
 * Backend event name (see App\Controller\Settings\ProxyController::FIREWALL_RULE_CHANGED_EVENT
 * on the PHP side, published from incomingConnections()) that lifecycle/index.js listens for
 * over /ws to add/remove the Windows Firewall inbound rules below. Keep this string in sync with
 * the PHP side — a mismatch breaks the toggle silently (issue #361, same lesson as issue #336),
 * which is why firewall.test.js cross-checks both sides against each other.
 *
 * @type {string}
 */
const FIREWALL_RULE_CHANGED_EVENT = 'firewall.rule.changed';

/**
 * BT listen port (see native/supervisor/qbittorrent.js's BT_PORT). Duplicated rather than
 * imported so this module has no dependency on qbittorrent.js's own requires (paths.js,
 * config.js); firewall.test.js cross-checks the two literals against each other instead.
 *
 * @type {number}
 */
const BT_PORT = 51413;

/** @type {{ TCP: string, UDP: string }} */
const RULE_NAMES = Object.freeze({
    TCP: 'AnimeDB qBittorrent (TCP)',
    UDP: 'AnimeDB qBittorrent (UDP)',
});

/**
 * @param {'TCP' | 'UDP'} protocol
 * @param {string} ruleName
 * @returns {string[]}
 */
function buildAddRuleArgs(protocol, ruleName) {
    return [
        'advfirewall', 'firewall', 'add', 'rule',
        `name=${ruleName}`,
        'dir=in',
        'action=allow',
        `protocol=${protocol}`,
        `localport=${BT_PORT}`,
    ];
}

/**
 * @param {string} ruleName
 * @returns {string[]}
 */
function buildDeleteRuleArgs(ruleName) {
    return ['advfirewall', 'firewall', 'delete', 'rule', `name=${ruleName}`];
}

/**
 * @param {boolean} enabled
 * @param {'TCP' | 'UDP'} protocol
 * @returns {string[]}
 */
function buildNetshArgs(enabled, protocol) {
    const ruleName = RULE_NAMES[protocol];

    return enabled ? buildAddRuleArgs(protocol, ruleName) : buildDeleteRuleArgs(ruleName);
}

/**
 * @param {string} value
 * @returns {string}
 */
function quotePowerShellArg(value) {
    return `'${value.replace(/'/g, "''")}'`;
}

/**
 * Builds the PowerShell command that runs the given netsh argv elevated (UAC prompt). Win32's
 * CreateProcess only ever sees a single command-line string, not an argv array — once
 * Start-Process re-joins the args with spaces, the rule name (which contains spaces/parens)
 * needs its own embedded double quotes to still parse as one netsh token.
 *
 * @param {string[]} argv
 * @returns {string}
 */
function buildElevatedNetshCommand(argv) {
    const commandLine = argv
        .map((arg) => (arg.startsWith('name=') ? `name="${arg.slice('name='.length)}"` : arg))
        .join(' ');

    return `Start-Process -FilePath 'netsh' -ArgumentList ${quotePowerShellArg(commandLine)} -Verb RunAs -Wait -WindowStyle Hidden`;
}

/**
 * Runs a single elevated netsh add/delete for one protocol. No-op on any platform but Windows
 * (the app only ships for Windows, see .claude-docs/architecture.md, but the jest suite runs on
 * Linux CI).
 *
 * @param {boolean} enabled
 * @param {'TCP' | 'UDP'} protocol
 * @returns {Promise<void>}
 */
function applyRule(enabled, protocol) {
    if (process.platform !== 'win32') return Promise.resolve();

    const command = buildElevatedNetshCommand(buildNetshArgs(enabled, protocol));

    return new Promise((resolve, reject) => {
        const child = spawn('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', command], { stdio: 'ignore' });

        child.on('error', reject);
        child.on('exit', (code) => {
            if (code === 0) {
                resolve();
            } else {
                reject(new Error(`netsh ${protocol} rule ${enabled ? 'add' : 'delete'} exited with code ${code}`));
            }
        });
    });
}

/**
 * Adds (enabled=true) or removes (enabled=false) the TCP+UDP Windows Firewall inbound rules for
 * the BT listen port, each its own elevated (UAC) netsh call. Called only in reaction to the
 * user's explicit settings-page toggle (FIREWALL_RULE_CHANGED_EVENT) — never at app startup or
 * install time (issue #361 acceptance: elevation only by explicit user action).
 *
 * @param {boolean} enabled
 * @returns {Promise<void>}
 */
async function applyIncomingConnections(enabled) {
    await applyRule(enabled, 'TCP');
    await applyRule(enabled, 'UDP');
}

module.exports = {
    FIREWALL_RULE_CHANGED_EVENT,
    BT_PORT,
    RULE_NAMES,
    buildAddRuleArgs,
    buildDeleteRuleArgs,
    buildNetshArgs,
    buildElevatedNetshCommand,
    applyIncomingConnections,
};
