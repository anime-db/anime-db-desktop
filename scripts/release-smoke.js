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
 * Release gate (issue #535): launches the BUILT application — `dist/win-unpacked/AnimeDB.exe`,
 * the same tree electron-builder wraps into the installer — and checks from the outside that its
 * HTTP surface actually works. Everything here is black-box on purpose: nothing from `native/` is
 * required, no environment is assembled by hand, no port is chosen for the app. Whatever the
 * shipped supervisor does on a user's machine is what gets measured, including the pieces this
 * repository has no other way to exercise (electron-builder's `files` globs, the packaged
 * `app/Caddyfile`, php.ini rendering into a fresh profile, migrations on an empty database).
 *
 * Why a separate user-data directory: `--user-data-dir` is honoured by Electron for
 * `app.getPath('userData')`, which is what `native/paths.js` builds every runtime path from. It
 * makes the run isolated (nothing touches an existing installation) and, more importantly,
 * knowable — the PID files the supervisor writes land where this script can find them without
 * guessing the product name.
 *
 * Why PID files rather than a fixed port: the app picks free ports at startup and publishes them
 * nowhere. `native/supervisor/pid-tracker.js` does write `var/pids/<name>.pid`, so the FrankenPHP
 * process can be located, and its listening sockets asked from the OS.
 *
 * Both listening sockets are probed with the same expectations rather than being told apart. Since
 * issue #532 the WS block serves the same root as the app block, so the two are indistinguishable
 * from outside — and asserting that BOTH answer is stricter than picking one, while also pinning
 * the count at exactly two.
 */

'use strict';

const fs      = require('fs');
const http    = require('http');
const os      = require('os');
const path    = require('path');
const { spawn, execFile } = require('child_process');

const rootDir = path.resolve(__dirname, '..');

// Derived from the same field electron-builder names the executable after, rather than spelled out
// again here: renaming the product would otherwise leave this gate looking for a file that no build
// produces, and the failure would read as "the app is missing" instead of "the name moved".
const PRODUCT_NAME = require('../package.json').build.productName;

const DEFAULT_APP_PATH = path.join(rootDir, 'dist', 'win-unpacked', `${PRODUCT_NAME}.exe`);

/** How long to wait for the supervisor to write a PID file and open its sockets. */
const DEFAULT_STARTUP_TIMEOUT_MS = 180_000;

/** Gap between polls while waiting for startup. Short enough to be responsive, long enough to be quiet. */
const POLL_INTERVAL_MS = 1_000;

/** Per-request timeout for the probes themselves — a hung socket must not eat the startup budget. */
const PROBE_TIMEOUT_MS = 15_000;

/**
 * What every listening socket of the packaged FrankenPHP must answer.
 *
 * `/health` alone would not do: `App\Controller\HealthController` runs a raw DBAL query and never
 * touches the ORM, so it answered 200 through the whole period when every catalogue page was a 500
 * (issue #533). `/` and the catalogue list go through repositories, which is the part that was
 * broken and invisible. `/ws` is expected to be refused with 426 rather than served: reaching that status means
 * the request was routed into PHP and `App\Controller\WsController` asked for an upgrade — before
 * issue #532 the same request got a Caddy-level `400 Client sent an HTTP request to an HTTPS server`.
 */
const EXPECTATIONS = [
    { path: '/health', status: 200, why: 'процесс поднялся и видит базу' },
    { path: '/',       status: 200, why: 'главная ходит в ORM (StorageRepository, AnimeRepository)' },
    // `watch_status` у списка обязателен: без него AnimeListRequestParser осознанно отвечает 400
    // (см. его parseFilter()). Первая версия гейта дёргала голый `/anime`, получала законные 400 и
    // объявляла сборку сломанной — проверка обязана слать валидный запрос, иначе она измеряет не
    // приложение, а собственную неточность.
    { path: '/anime?watch_status=plan', status: 200, why: 'список каталога ходит в ORM' },
    { path: '/ws',     status: 426, why: 'WS-эндпоинт доехал до PHP и просит апгрейд, а не отдан TLS-листенером' },
];

/**
 * @param {string[]} argv
 * @returns {{ appPath?: string, userDataDir?: string, timeoutMs?: number, keepUserData?: boolean }}
 */
function parseArgs(argv) {
    const options = {};
    for (let i = 0; i < argv.length; i++) {
        switch (argv[i]) {
            case '--app':
                options.appPath = argv[++i];
                break;
            case '--user-data-dir':
                options.userDataDir = argv[++i];
                break;
            case '--timeout':
                options.timeoutMs = Number(argv[++i]);
                break;
            case '--keep-user-data':
                options.keepUserData = true;
                break;
        }
    }
    return options;
}

/**
 * Parses the output of `Get-NetTCPConnection -State Listen | ConvertTo-Json`. PowerShell collapses
 * a single-element array into a bare object, which is the classic way this kind of parsing breaks
 * the day a build happens to open exactly one socket — both shapes are accepted here.
 *
 * @param {string} stdout
 * @returns {{ address: string, port: number }[]} sorted by port, so the result is stable to compare
 */
function parseListeners(stdout) {
    const trimmed = String(stdout).trim();
    if (trimmed === '') return [];

    const parsed = JSON.parse(trimmed);
    const rows = Array.isArray(parsed) ? parsed : [parsed];

    return rows
        .map((row) => ({ address: String(row.LocalAddress), port: Number(row.LocalPort) }))
        .sort((a, b) => a.port - b.port);
}

/**
 * Turns the observed sockets into the verdict this gate is about. Kept separate from the process
 * work so the rule — two sockets, both on loopback — is testable without Windows.
 *
 * @param {{ address: string, port: number }[]} listeners
 * @returns {string[]} complaints, empty when the set is what a correct build opens
 */
function checkListeners(listeners) {
    const problems = [];

    if (listeners.length !== 2) {
        problems.push(
            `ожидалось ровно два слушающих сокета FrankenPHP (APP и WS), найдено ${listeners.length}: ` +
            (listeners.map((l) => `${l.address}:${l.port}`).join(', ') || '(ни одного)'),
        );
    }

    for (const listener of listeners) {
        // Not "does not contain 0.0.0.0": Caddy renders an unrestricted listener as `:PORT`, and the
        // OS reports it as 0.0.0.0 — but a future IPv6-only regression would report `::` instead and
        // slip through a blocklist. Loopback is the whole requirement, so assert it directly.
        if (listener.address !== '127.0.0.1') {
            problems.push(
                `сокет ${listener.address}:${listener.port} слушает не только loopback — ` +
                'ожидается bind на 127.0.0.1 (см. app/Caddyfile, issue #532)',
            );
        }
    }

    return problems;
}

/**
 * @param {{ path: string, status: number }[]} expectations
 * @param {{ port: number, path: string, status: number|null, error?: string }[]} responses
 * @returns {string[]} complaints, empty when every probe met its expectation
 */
function checkResponses(expectations, responses) {
    const problems = [];

    for (const response of responses) {
        const expectation = expectations.find((e) => e.path === response.path);
        if (expectation === undefined) continue;

        if (response.status === expectation.status) continue;

        problems.push(
            `127.0.0.1:${response.port}${response.path} — ожидался ${expectation.status} ` +
            `(${expectation.why}), получено ${response.error ?? response.status}`,
        );
    }

    return problems;
}

/** @returns {Promise<void>} */
function delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * @param {string} command
 * @param {string[]} args
 * @returns {Promise<string>}
 */
function exec(command, args) {
    return new Promise((resolve, reject) => {
        execFile(command, args, { encoding: 'utf8', windowsHide: true }, (err, stdout) => {
            if (err) reject(err);
            else resolve(stdout);
        });
    });
}

/**
 * Asks the OS which sockets a process is listening on. `-ErrorAction SilentlyContinue` keeps the
 * "no sockets yet" case an empty result instead of a non-zero exit while the app is still starting.
 *
 * @param {number} pid
 * @returns {Promise<{ address: string, port: number }[]>}
 */
async function listenersOf(pid) {
    const stdout = await exec('powershell', [
        '-NoProfile',
        '-NonInteractive',
        '-Command',
        `Get-NetTCPConnection -OwningProcess ${pid} -State Listen -ErrorAction SilentlyContinue ` +
        '| Select-Object LocalAddress,LocalPort | ConvertTo-Json -Compress',
    ]);

    return parseListeners(stdout);
}

/**
 * Services the app pins to a fixed port instead of discovering one. When startup never gets far
 * enough to open FrankenPHP's sockets, these are the only things left to ask — and the answer names
 * the step that blocked. Issue #552 is exactly that case: the supervisor waits for qBittorrent's
 * WebUI before starting anything else, and "порты не открылись" said nothing about which of the two
 * plausible causes (403 from an auth check, or a WebUI slower than the 30s timeout) actually
 * happened, because the status code was never captured.
 */
const FIXED_PORT_SERVICES = [
    // native/supervisor/qbittorrent.js's WEBUI_PORT, duplicated rather than imported: that module
    // requires paths.js, which requires electron, and this script is deliberately Electron-free.
    { name: 'qBittorrent WebUI', port: 18080, path: '/api/v2/app/version' },
];

/**
 * @param {number} port
 * @param {string} requestPath
 * @param {boolean} [withBody] capture the first bytes of the response — diagnostics only
 * @returns {Promise<{ port: number, path: string, status: number|null, body?: string, error?: string }>}
 */
function probe(port, requestPath, withBody = false) {
    return new Promise((resolve) => {
        const request = http.get(
            { host: '127.0.0.1', port, path: requestPath, timeout: PROBE_TIMEOUT_MS },
            (response) => {
                // The body is irrelevant to the gate itself, but it has to be drained either way: an
                // unread response keeps the socket open and the script would not exit on its own.
                if (!withBody) {
                    response.resume();
                    response.on('end', () => resolve({ port, path: requestPath, status: response.statusCode }));
                    return;
                }

                let body = '';
                response.setEncoding('utf8');
                response.on('data', (chunk) => {
                    if (body.length < 200) body += chunk;
                });
                response.on('end', () => resolve({
                    port,
                    path: requestPath,
                    status: response.statusCode,
                    body: body.slice(0, 200).replace(/\s+/g, ' ').trim(),
                }));
            },
        );

        request.on('timeout', () => {
            request.destroy();
            resolve({ port, path: requestPath, status: null, error: `нет ответа за ${PROBE_TIMEOUT_MS} мс` });
        });

        request.on('error', (err) => {
            resolve({ port, path: requestPath, status: null, error: err.message });
        });
    });
}

/**
 * @param {string} userDataDir
 * @returns {string}
 */
function pidFilePath(userDataDir) {
    // Mirrors native/supervisor/pid-tracker.js's layout: <userData>/var/pids/<name>.pid, where the
    // `var` segment comes from native/paths.js's getRuntimeDir().
    return path.join(userDataDir, 'var', 'pids', 'frankenphp.pid');
}

/**
 * Waits until the supervisor has written the FrankenPHP PID and that process is listening on both
 * of its sockets. Returns as soon as two are up rather than after a fixed sleep, so a healthy build
 * costs seconds and only a broken one spends the whole budget.
 *
 * @param {string} userDataDir
 * @param {number} timeoutMs
 * @returns {Promise<{ pid: number, listeners: { address: string, port: number }[] }>}
 * @throws {Error} when the deadline passes before both sockets are up
 */
async function waitForListeners(userDataDir, timeoutMs) {
    const deadline = Date.now() + timeoutMs;
    const pidFile = pidFilePath(userDataDir);
    let lastSeen = 'PID-файл ещё не создан';

    while (Date.now() < deadline) {
        if (fs.existsSync(pidFile)) {
            const pid = Number(fs.readFileSync(pidFile, 'utf8').trim());

            if (Number.isInteger(pid) && pid > 0) {
                const listeners = await listenersOf(pid);

                if (listeners.length >= 2) return { pid, listeners };

                lastSeen = `PID ${pid} найден, слушающих сокетов: ${listeners.length}`;
            }
        }

        await delay(POLL_INTERVAL_MS);
    }

    throw new Error(`Приложение не открыло свои порты за ${timeoutMs} мс. Последнее состояние: ${lastSeen}`);
}

/**
 * Asks every fixed-port service what it answers right now. Runs only on the failure path: on a
 * healthy run it would add noise, on a broken one it is often the whole diagnosis.
 *
 * @returns {Promise<void>}
 */
async function dumpFixedPortServices() {
    console.error('\nСлужбы на фиксированных портах в момент отказа:');

    for (const service of FIXED_PORT_SERVICES) {
        const response = await probe(service.port, service.path, true);

        console.error(
            `  ${service.name} — GET 127.0.0.1:${service.port}${service.path} -> ` +
            `${response.error ?? response.status}${response.body ? ` | ${response.body}` : ''}`,
        );
    }
}

/**
 * Prints whatever the app managed to write before failing. Without this a red gate says only
 * "ports never opened", which is the least useful half of the story — the supervisor's own logs
 * name the step that broke.
 *
 * @param {string} userDataDir
 */
function dumpLogs(userDataDir) {
    const logDir = path.join(userDataDir, 'var', 'log');

    if (!fs.existsSync(logDir)) {
        console.error(`\nЛогов нет: ${logDir} не создан — приложение упало до того, как супервизор начал писать.`);

        // Что в профиле всё-таки появилось — единственный оставшийся признак того, как далеко
        // дошёл запуск. Пустой каталог означает, что Electron не стартовал вовсе; файлы
        // Chromium-профиля без `var/` — что стартовал, но до супервизора не добрался.
        if (fs.existsSync(userDataDir)) {
            console.error(`Содержимое профиля ${userDataDir}: ${fs.readdirSync(userDataDir).join(', ') || '(пусто)'}`);
        }

        return;
    }

    for (const name of fs.readdirSync(logDir).sort()) {
        const file = path.join(logDir, name);
        if (!fs.statSync(file).isFile()) continue;

        console.error(`\n===== ${file} =====`);
        console.error(fs.readFileSync(file, 'utf8').trimEnd() || '(пусто)');
    }
}

/**
 * Removes the throwaway profile without ever becoming the reason the run failed.
 *
 * Windows releases file handles asynchronously: right after `taskkill` the Chromium profile files
 * (`DIPS` and friends) are still locked, and a plain `rmSync` throws EBUSY. Worse, it threw from a
 * `finally`, which replaced whatever verdict the gate had just reached with a rimraf stack trace —
 * the first CI run of this script reported a locked temp file instead of the real startup failure.
 * Leftovers in the runner's temp directory cost nothing; a masked verdict costs the whole run.
 *
 * @param {string} dir
 * @param {number} [attempts]
 * @returns {Promise<void>}
 */
async function removeQuietly(dir, attempts = 5) {
    for (let attempt = 1; attempt <= attempts; attempt++) {
        try {
            fs.rmSync(dir, { recursive: true, force: true });
            return;
        } catch (err) {
            if (attempt === attempts) {
                console.error(`Не удалось убрать временный профиль ${dir}: ${err.message}. Пропускаю.`);
                return;
            }
            await delay(POLL_INTERVAL_MS);
        }
    }
}

/**
 * @param {import('child_process').ChildProcess|null} child
 * @returns {Promise<void>}
 */
async function terminate(child) {
    if (child === null || child.exitCode !== null || child.signalCode !== null) return;

    // taskkill /T, not child.kill(): the app is a tree — Electron, FrankenPHP, Meilisearch,
    // qbittorrent-nox, the messenger consumer. Killing only the root would leave the children
    // holding their ports and the runner's next step would meet a dirty machine.
    try {
        await exec('taskkill', ['/PID', String(child.pid), '/T', '/F']);
    } catch {
        child.kill('SIGKILL');
    }
}

/**
 * @param {{ appPath?: string, userDataDir?: string, timeoutMs?: number, keepUserData?: boolean }} [options]
 * @returns {Promise<{ ok: boolean, exitCode: number, message: string, problems: string[] }>}
 */
// Files that MUST be present in the packaged tree for the installer to be distributable at all:
// our own GPLv3 text, the third-party index and the license texts of the bundled binaries. Every
// path is relative to the installation directory. Three different mechanisms put them there
// (`extraFiles` for the first two groups, `files` for what download-bins.js extracts into
// bin/licenses/, and the qbittorrent-nox bundle carrying its own THIRD-PARTY-LICENSES/), and each
// of them fails silently: a mistyped glob, a skipped extraction or an upstream that stopped
// shipping its license file all produce a working application that is simply missing attribution.
// This is the only place in the pipeline that sees the real shipped tree.
const REQUIRED_LICENSE_FILES = [
    'LICENSE.txt',
    'LICENSE.electron.txt',
    'LICENSES.chromium.html',
    'THIRD-PARTY-LICENSES/README.md',
    'THIRD-PARTY-LICENSES/PHP-NOTICE.txt',
    'THIRD-PARTY-LICENSES/FrankenPHP-NOTICE.txt',
    'THIRD-PARTY-LICENSES/OpenSSL-NOTICE.txt',
    'THIRD-PARTY-LICENSES/ICU-NOTICE.txt',
    'THIRD-PARTY-LICENSES/GD-CODECS-NOTICE.txt',
    'THIRD-PARTY-LICENSES/Runtime-libraries-NOTICE.txt',
    'THIRD-PARTY-LICENSES/Meilisearch-NOTICE.txt',
    'THIRD-PARTY-LICENSES/SQLite-NOTICE.txt',
    'THIRD-PARTY-LICENSES/texts/Apache-2.0.txt',
    'THIRD-PARTY-LICENSES/texts/AGPL-3.0.txt',
    'resources/app/bin/licenses/frankenphp/license.txt',
    'resources/app/bin/licenses/frankenphp/readme-redist-bins.txt',
    'resources/app/bin/qbittorrent-nox/THIRD-PARTY-LICENSES/README.md',
];

/**
 * @param {string} appDir installation directory (the one holding the executable)
 * @returns {string[]} human-readable problems, empty when every required file is in place
 */
function checkLicenseFiles(appDir) {
    return REQUIRED_LICENSE_FILES
        .filter((rel) => !fs.existsSync(path.join(appDir, rel)))
        .map((rel) => `Нет обязательного лицензионного файла: ${rel}`);
}

async function run({
    appPath = DEFAULT_APP_PATH,
    userDataDir,
    timeoutMs = DEFAULT_STARTUP_TIMEOUT_MS,
    keepUserData = false,
} = {}) {
    if (process.platform !== 'win32') {
        return {
            ok: false,
            exitCode: 1,
            message:
                'Гейт релиза выполняется только на Windows: он запускает собранное приложение и спрашивает ' +
                'у ОС его слушающие сокеты через Get-NetTCPConnection.',
            problems: [],
        };
    }

    if (!fs.existsSync(appPath)) {
        return {
            ok: false,
            exitCode: 1,
            message: `Собранного приложения нет: ${appPath}. Сначала выполни:\n  npm run build`,
            problems: [],
        };
    }

    // Before spending Windows runner minutes on a launch: a build missing attribution must not be
    // published regardless of whether it starts.
    const licenseProblems = checkLicenseFiles(path.dirname(appPath));
    if (licenseProblems.length > 0) {
        return {
            ok: false,
            exitCode: 1,
            message: 'Собранное приложение нельзя распространять: в поставке нет лицензионных файлов.',
            problems: licenseProblems,
        };
    }

    const profileDir = userDataDir ?? fs.mkdtempSync(path.join(os.tmpdir(), 'anime-db-release-smoke-'));
    let child = null;

    try {
        child = spawn(appPath, [`--user-data-dir=${profileDir}`], { stdio: 'ignore', windowsHide: true });

        const exited = new Promise((resolve) => child.once('exit', (code) => resolve(code)));
        const started = waitForListeners(profileDir, timeoutMs);

        // Racing the two matters: an app that dies during startup would otherwise be reported as
        // "ports never opened" only after the full timeout, hiding both the real cause and, in CI,
        // three minutes of billed Windows time.
        const outcome = await Promise.race([
            started.then((value) => ({ kind: 'started', value })),
            exited.then((code) => ({ kind: 'exited', code })),
        ]);

        if (outcome.kind === 'exited') {
            dumpLogs(profileDir);
            return {
                ok: false,
                exitCode: 1,
                message: `Приложение завершилось на старте с кодом ${outcome.code}, не открыв портов.`,
                problems: [],
            };
        }

        const { pid, listeners } = outcome.value;
        console.log(`FrankenPHP: PID ${pid}, слушает ${listeners.map((l) => `${l.address}:${l.port}`).join(', ')}`);

        const problems = checkListeners(listeners);

        const responses = [];
        for (const listener of listeners) {
            for (const expectation of EXPECTATIONS) {
                const response = await probe(listener.port, expectation.path);
                console.log(
                    `  127.0.0.1:${response.port}${response.path} -> ${response.error ?? response.status}` +
                    ` (ожидалось ${expectation.status})`,
                );
                responses.push(response);
            }
        }

        problems.push(...checkResponses(EXPECTATIONS, responses));

        if (problems.length > 0) {
            dumpLogs(profileDir);
            return { ok: false, exitCode: 1, message: 'Собранное приложение работает не так, как должно.', problems };
        }

        return { ok: true, exitCode: 0, message: 'Собранное приложение поднялось и отвечает как ожидается.', problems: [] };
    } catch (err) {
        // The startup wait rejects on its deadline, and that is the failure this gate exists to
        // report — so it has to arrive as a verdict with the app's own logs attached, not as a
        // stack trace from somewhere inside the polling loop.
        await dumpFixedPortServices();
        dumpLogs(profileDir);

        return { ok: false, exitCode: 1, message: err.message, problems: [] };
    } finally {
        await terminate(child);
        if (!keepUserData && userDataDir === undefined) {
            await removeQuietly(profileDir);
        }
    }
}

async function main() {
    const result = await run(parseArgs(process.argv.slice(2)));

    if (result.ok) {
        console.log(result.message);
        process.exit(0);
    }

    console.error(result.message);
    for (const problem of result.problems) {
        console.error(`  - ${problem}`);
    }

    process.exit(result.exitCode);
}

if (require.main === module) {
    main();
}

module.exports = {
    run,
    parseArgs,
    parseListeners,
    checkListeners,
    checkResponses,
    checkLicenseFiles,
    pidFilePath,
    EXPECTATIONS,
    REQUIRED_LICENSE_FILES,
    DEFAULT_APP_PATH,
};
