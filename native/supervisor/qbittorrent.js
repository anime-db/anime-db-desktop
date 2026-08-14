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

const { spawn }         = require('child_process');
const { EventEmitter }  = require('events');
const fs                = require('fs');
const path               = require('path');
const paths               = require('../paths');
const { getProxySettings } = require('../config');
const { waitForHealth }    = require('./healthcheck');
const { pruneOldLogs, openLogStream } = require('./logrotate');
const pidTracker           = require('./pid-tracker');

const events = new EventEmitter();

const BINARY = path.join(__dirname, '..', '..', 'bin', 'qbittorrent-nox', 'qbittorrent-nox.exe');

const LOG_PREFIX = 'qbittorrent';
const LOG_MAX    = 7;

/**
 * Ports are PINNED, not found via findFreePort(): the WebUI port must never listen beyond
 * loopback (see spawnProcess()/qBittorrent.ini "WebUI\Address"), and the BT listen port must
 * stay stable across restarts for the installer's firewall rule to remain valid (issue #345).
 */
const WEBUI_PORT = 18080;
const BT_PORT    = 51413;

/** Задержки backoff при перезапуске: 1s, 2s, 4s, … до 30s. */
const BACKOFF = [1000, 2000, 4000, 8000, 16000, 30000];

let child     = null;
let stopping  = false;
let logStream = null;

/**
 * Sets (or replaces) a single "key=value" line inside an INI section, preserving every other
 * line/section verbatim. A hand-rolled line patch rather than a full INI parser: this profile's
 * qBittorrent.ini is otherwise entirely owned by qbittorrent-nox itself (statistics, per-torrent
 * WebUI-set preferences) and must survive untouched across our own re-seeding on every start().
 *
 * @param {string} content
 * @param {string} section
 * @param {string} key
 * @param {string} rawValue already-formatted INI value (quoted if it's a string)
 * @returns {string}
 */
function upsertIniValue(content, section, key, rawValue) {
    const lines = content.length ? content.split('\n') : [];
    const sectionHeader = `[${section}]`;
    const sectionStart = lines.findIndex((line) => line.trim() === sectionHeader);

    if (sectionStart === -1) {
        if (lines.length > 0 && lines[lines.length - 1].trim() !== '') lines.push('');
        lines.push(sectionHeader, `${key}=${rawValue}`);
        return lines.join('\n');
    }

    let sectionEnd = lines.findIndex((line, i) => i > sectionStart && /^\[.*\]$/.test(line.trim()));
    if (sectionEnd === -1) sectionEnd = lines.length;

    const keyPrefix = `${key}=`;
    const existingIdx = lines.findIndex((line, i) => i > sectionStart && i < sectionEnd && line.startsWith(keyPrefix));

    if (existingIdx !== -1) {
        lines[existingIdx] = `${key}=${rawValue}`;
    } else {
        // Insert right after the section's last non-blank line, not right before the next
        // section header — avoids splitting the section's own trailing blank-line separator.
        let insertAt = sectionEnd;
        while (insertAt > sectionStart + 1 && lines[insertAt - 1].trim() === '') insertAt--;
        lines.splice(insertAt, 0, `${key}=${rawValue}`);
    }

    return lines.join('\n');
}

/**
 * Qt's INI writer always accepts a double-quoted string (its own escaping is a superset of the
 * unquoted form), so quoting unconditionally here is safe for values qbittorrent-nox will re-read.
 *
 * @param {string} value
 * @returns {string}
 */
function quoteIniString(value) {
    return `"${String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`;
}

/**
 * True only for a fully-specified SOCKS5 proxy — anything else (mode "none", protocol "http",
 * missing host/port) means qbittorrent-nox must run with no proxy configured at all.
 *
 * @param {Record<string, unknown> | null} proxy
 * @returns {boolean}
 */
function isSocks5Proxy(proxy) {
    return Boolean(proxy && proxy.mode === 'manual' && proxy.protocol === 'socks5' && proxy.host && proxy.port);
}

/**
 * Writes WebUI/BT ports, DHT/PEX and (when configured) the SOCKS5 proxy into qBittorrent.ini
 * BEFORE the process is spawned. This must happen pre-spawn: libtorrent applies fast-resume data
 * (and can announce/connect to trackers/peers) immediately on session start, before qbittorrent-nox
 * would otherwise apply a proxy pushed through the WebUI API — a direct, unproxied announce would
 * already have happened by then (issue #345).
 *
 * @param {Record<string, unknown> | null} proxy
 */
function seedConfig(proxy) {
    const configPath = paths.getQbittorrentConfigPath();
    fs.mkdirSync(path.dirname(configPath), { recursive: true });

    let content = fs.existsSync(configPath) ? fs.readFileSync(configPath, 'utf8') : '';

    const socks5 = isSocks5Proxy(proxy);

    const upserts = [
        // "--confirm-legal-notice" (passed on every spawn below) already suppresses the
        // interactive prompt; this is the config-file equivalent, kept in sync for belt-and-suspenders.
        ['LegalNotice', 'Accepted', 'true'],

        ['WebUI', 'Port', String(WEBUI_PORT)],
        // Overrides qBittorrent's own default of "*" (bind all interfaces) — the WebUI must never
        // be reachable from outside loopback.
        ['WebUI', 'Address', quoteIniString('127.0.0.1')],
        // No WebUI port is exposed outside loopback, so bypassing auth for localhost callers is
        // safe and avoids having to generate/rotate a PBKDF2 credential the driver would need too.
        ['WebUI', 'LocalHostAuth', 'false'],
        // WebUI is loopback-only; disabling host-header validation avoids a 401 when the
        // driver's Host header (127.0.0.1:<port>) is not in qBittorrent's default allowlist.
        ['WebUI', 'HostHeaderValidation', 'false'],

        ['BitTorrent', 'Session\\Port', String(BT_PORT)],
        // DHT/PEX stay ON globally: for non-private torrents they are the fallback when the
        // tracker is unreachable and must not be disabled (issue #345).
        ['BitTorrent', 'Session\\DHTEnabled', 'true'],
        ['BitTorrent', 'Session\\PeXEnabled', 'true'],
        ['BitTorrent', 'Session\\ProxyPeerConnections', String(socks5)],

        ['Network', 'Proxy\\Type', socks5 ? 'SOCKS5' : 'None'], // qBittorrent 4.6+/5.x serialises Net::ProxyType as a string enum, not an int
        ['Network', 'Proxy\\HostnameLookupEnabled', 'true'],
        // "Profiles\BitTorrent" is qBittorrent's own per-traffic-type opt-in — without it, BT
        // traffic ignores the proxy configured above even though it is otherwise fully set.
        ['Network', 'Proxy\\Profiles\\BitTorrent', String(socks5)],
    ];

    if (socks5) {
        const hasAuth = Boolean(proxy.username);
        upserts.push(
            ['Network', 'Proxy\\IP', quoteIniString(proxy.host)],
            ['Network', 'Proxy\\Port', String(proxy.port)],
            ['Network', 'Proxy\\AuthEnabled', String(hasAuth)],
        );
        if (hasAuth) {
            upserts.push(
                ['Network', 'Proxy\\Username', quoteIniString(proxy.username)],
                ['Network', 'Proxy\\Password', quoteIniString(proxy.password || '')],
            );
        }
    }

    for (const [section, key, rawValue] of upserts) {
        content = upsertIniValue(content, section, key, rawValue);
    }

    fs.writeFileSync(configPath, content, 'utf8');
}

/**
 * Запускает qbittorrent-nox и при падении перезапускает с backoff.
 * "--confirm-legal-notice" is passed unconditionally (idempotent once already accepted) instead
 * of tracking first-run state — without it the very first launch never brings the WebUI up at all.
 */
function spawnProcess(backoffIdx = 0) {
    if (stopping) return;

    fs.mkdirSync(paths.getQbittorrentProfileDir(), { recursive: true });

    child = spawn(BINARY, [
        `--profile=${paths.getQbittorrentProfileDir()}`,
        `--webui-port=${WEBUI_PORT}`,
        '--confirm-legal-notice',
    ], {
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    pidTracker.writePid(LOG_PREFIX, child.pid);

    child.stdout.on('data', (d) => logStream.write(d));
    child.stderr.on('data', (d) => logStream.write(d));

    child.on('exit', (code) => {
        if (stopping) return;
        events.emit('exit', code);
        const delay = BACKOFF[Math.min(backoffIdx, BACKOFF.length - 1)];
        console.error(`[qbittorrent] вышел с кодом ${code}, перезапуск через ${delay}ms`);
        setTimeout(() => spawnProcess(backoffIdx + 1), delay);
    });
}

/**
 * Убивает процесс-сироту, оставленный предыдущим сеансом (см. pid-tracker.js). Должен быть
 * вызван супервизором до того, как запущен хоть один дочерний процесс текущего сеанса — иначе
 * PID, переиспользованный ОС для процесса на том же бинарнике, пройдёт проверку имени образа и
 * killOrphan() убьёт только что запущенный процесс текущего сеанса (issue #390).
 *
 * @returns {Promise<void>}
 */
function killOrphan() {
    return pidTracker.killOrphan(LOG_PREFIX, BINARY);
}

/**
 * Запускает qbittorrent-nox: сеет qBittorrent.ini (порты, DHT/PEX, прокси) → спавнит процесс →
 * ждёт готовности WebUI через GET /api/v2/app/version → возвращает фиксированные порты.
 *
 * @returns {Promise<{ webuiPort: number, btPort: number }>}
 */
async function start() {
    stopping = false;
    seedConfig(getProxySettings());

    const logDir = path.join(paths.getRuntimeDir(), 'log');
    pruneOldLogs(logDir, LOG_PREFIX, LOG_MAX);
    logStream = openLogStream(logDir, LOG_PREFIX);

    spawnProcess();
    await waitForHealth(WEBUI_PORT, { path: '/api/v2/app/version' });
    return { webuiPort: WEBUI_PORT, btPort: BT_PORT };
}

/**
 * Graceful shutdown: SIGTERM → 500ms → SIGKILL.
 *
 * @returns {Promise<void>}
 */
function stop() {
    stopping = true;
    if (!child) return Promise.resolve();

    const proc = child;
    child = null;

    return new Promise((resolve) => {
        const timer = setTimeout(() => proc.kill('SIGKILL'), 500);

        proc.on('exit', () => {
            clearTimeout(timer);
            if (logStream) {
                logStream.end();
                logStream = null;
            }
            pidTracker.clearPid(LOG_PREFIX);
            resolve();
        });

        proc.kill('SIGTERM');
    });
}

/**
 * Best-effort синхронный килл на случай аварийного выхода Electron, который не проходит через
 * штатный stop() (см. process.on('exit') в lifecycle/index.js) — дождаться асинхронного
 * graceful-shutdown там уже нельзя, поэтому сразу SIGKILL.
 */
function killSync() {
    if (!child) return;
    try {
        child.kill('SIGKILL');
    } catch {
        // процесс уже завершился
    }
}

module.exports = {
    start,
    stop,
    killSync,
    killOrphan,
    events,
    WEBUI_PORT,
    BT_PORT,
    seedConfig,
    upsertIniValue,
    isSocks5Proxy,
};
