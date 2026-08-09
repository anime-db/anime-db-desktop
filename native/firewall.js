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
const path       = require('path');

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

/**
 * qbittorrent-nox executable path (see native/supervisor/qbittorrent.js's BINARY). Duplicated
 * rather than imported for the same no-dependency reason as BT_PORT above. Scoping the rule to
 * this program (rather than leaving `program=` unset) keeps the opened port from being usable by
 * any other locally-bound process.
 *
 * @type {string}
 */
const BINARY = path.join(__dirname, '..', 'bin', 'qbittorrent-nox', 'qbittorrent-nox.exe');

/** @type {{ TCP: string, UDP: string }} */
const RULE_NAMES = Object.freeze({
    TCP: 'AnimeDB qBittorrent (TCP)',
    UDP: 'AnimeDB qBittorrent (UDP)',
});

/**
 * Scoped to `program=` (only qbittorrent-nox may listen on the port) and `profile=private`
 * (home/work networks only, not Public) — an unscoped rule would open the port for any locally
 * bound process on any network, including untrusted public Wi-Fi.
 *
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
        `program=${BINARY}`,
        `protocol=${protocol}`,
        `localport=${BT_PORT}`,
        'profile=private',
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
 * netsh argv keys whose value may contain spaces/parens (rule name, program path) and therefore
 * needs its own embedded double quotes to survive as one token once the argv is flattened to a
 * single command-line string (see buildNetshCommandLine doc).
 *
 * @type {string[]}
 */
const QUOTED_ARG_KEYS = ['name=', 'program='];

/**
 * @param {string} arg
 * @returns {string}
 */
function quoteNetshArg(arg) {
    const key = QUOTED_ARG_KEYS.find((prefix) => arg.startsWith(prefix));

    return key ? `${key}"${arg.slice(key.length)}"` : arg;
}

/**
 * Flattens a netsh argv array into the single command-line string netsh itself expects. Win32's
 * CreateProcess only ever sees a single command-line string, not an argv array — so `name=` and
 * `program=` values (which may contain spaces/parens) need their own embedded double quotes,
 * otherwise netsh would see them split into several unrelated tokens and fail to parse.
 *
 * @param {string[]} argv
 * @returns {string}
 */
function buildNetshCommandLine(argv) {
    return argv.map(quoteNetshArg).join(' ');
}

/**
 * Builds the elevated (UAC) PowerShell command that applies both the TCP and UDP rules in a
 * single elevated session, so one toggle click prompts UAC exactly once instead of twice (one
 * per protocol). The inner script is passed via `-EncodedCommand` (base64 UTF-16LE) rather than
 * as inline quoted text — that sidesteps having to nest PowerShell's own quoting rules two levels
 * deep (outer non-elevated `-Command` invoking `Start-Process`, which itself launches an elevated
 * `powershell.exe` running the netsh calls).
 *
 * @param {boolean} enabled
 * @returns {string}
 */
function buildElevatedNetshCommand(enabled) {
    const script = ['TCP', 'UDP']
        .map((protocol) => `netsh ${buildNetshCommandLine(buildNetshArgs(enabled, protocol))}`)
        .join('; ');

    const encodedCommand = Buffer.from(script, 'utf16le').toString('base64');

    return "Start-Process -FilePath 'powershell.exe' "
        + `-ArgumentList '-NoProfile -NonInteractive -EncodedCommand ${encodedCommand}' `
        + '-Verb RunAs -Wait -WindowStyle Hidden';
}

/**
 * Adds (enabled=true) or removes (enabled=false) the TCP+UDP Windows Firewall inbound rules for
 * the BT listen port via a single elevated (UAC) session — see buildElevatedNetshCommand(). No-op
 * on any platform but Windows (the app only ships for Windows, see .claude-docs/architecture.md,
 * but the jest suite runs on Linux CI). Called only in reaction to the user's explicit
 * settings-page toggle (FIREWALL_RULE_CHANGED_EVENT) — never at app startup or install time
 * (issue #361 acceptance: elevation only by explicit user action).
 *
 * @param {boolean} enabled
 * @returns {Promise<void>}
 */
function applyIncomingConnections(enabled) {
    if (process.platform !== 'win32') return Promise.resolve();

    const command = buildElevatedNetshCommand(enabled);

    return new Promise((resolve, reject) => {
        const child = spawn('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', command], { stdio: 'ignore' });

        child.on('error', reject);
        child.on('exit', (code) => {
            if (code === 0) {
                resolve();
            } else {
                reject(new Error(`netsh incoming connections ${enabled ? 'enable' : 'disable'} exited with code ${code}`));
            }
        });
    });
}

module.exports = {
    FIREWALL_RULE_CHANGED_EVENT,
    BT_PORT,
    RULE_NAMES,
    buildAddRuleArgs,
    buildDeleteRuleArgs,
    buildNetshArgs,
    buildNetshCommandLine,
    buildElevatedNetshCommand,
    applyIncomingConnections,
};
