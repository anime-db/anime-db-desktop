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
 * Uploads a release to every mirror from MIRROR_CREDS over FTP with TLS required, then checks the
 * result over HTTPS.
 *
 * Usage:   node scripts/publish-update-mirror.js [release-dir]
 *          release-dir (default `dist`) holds latest.yml, latest.yml.sig, and the files latest.yml
 *          names in files[].url (plus their .blockmap when present)
 * Env:     MIRROR_CREDS  JSON object keyed by mirror id; every entry:
 *            host        FTP host as it appears in the server certificate
 *            user, password
 *            dir         application directory relative to the FTP login root
 *            public_url  HTTPS base URL of that directory, used for the check
 *            protocol    optional, `ftp` (explicit TLS, default) or `ftps` (implicit TLS)
 *            port        optional, 21 / 990 by default
 * Layout:  <dir>/<version>/<file>..., <dir>/latest.yml.sig, <dir>/latest.yml, uploaded in that
 *          order, so latest.yml never points at files that are not there yet. Nothing is deleted:
 *          older versions are needed for differential downloads.
 * Check:   <public_url>/latest.yml and latest.yml.sig must equal the uploaded bytes; every file of
 *          the version must answer 200 with the uploaded size. Retried with a growing delay: a
 *          shared host can lag between FTP and web.
 * Exit:    0 published, or an upload/check failure remained after the retries (reported as
 *          `::warning` and skipped: the other source stays authoritative); 1 bad input: no files to
 *          upload, invalid or missing MIRROR_CREDS; 2 wrong usage
 *
 * Needs Node 22 and curl, no node_modules. Certificates are always verified.
 */

const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const ATTEMPTS = 4;
const BACKOFF_MS = 2000;
const REQUIRED_FIELDS = ['host', 'user', 'password', 'dir', 'public_url'];

class InputError extends Error {}

function parseCreds(raw) {
    let creds;
    try {
        creds = JSON.parse(raw ?? '');
    } catch {
        throw new InputError('MIRROR_CREDS is missing or not valid JSON');
    }
    if (creds === null || typeof creds !== 'object' || Array.isArray(creds) || Object.keys(creds).length === 0) {
        throw new InputError('MIRROR_CREDS must be a non-empty object keyed by mirror id');
    }
    for (const [id, entry] of Object.entries(creds)) {
        for (const field of REQUIRED_FIELDS) {
            if (typeof entry?.[field] !== 'string' || entry[field] === '') {
                throw new InputError(`MIRROR_CREDS[${id}].${field} is missing`);
            }
        }
        if (!/^https:\/\//.test(entry.public_url)) {
            throw new InputError(`MIRROR_CREDS[${id}].public_url must be an https URL`);
        }
        if (entry.protocol !== undefined && !['ftp', 'ftps'].includes(entry.protocol)) {
            throw new InputError(`MIRROR_CREDS[${id}].protocol must be ftp or ftps`);
        }
        if (/[\r\n]/.test(entry.user + entry.password)) {
            throw new InputError(`MIRROR_CREDS[${id}] user and password must not contain line breaks`);
        }
        if (entry.port !== undefined && !(/^[0-9]+$/.test(String(entry.port)) && entry.port !== true && Number(entry.port) > 0 && Number(entry.port) < 65536)) {
            throw new InputError(`MIRROR_CREDS[${id}].port must be an integer from 1 to 65535`);
        }
    }
    return creds;
}

/**
 * @returns {{version: string, files: {name: string, rel: string, data: Buffer}[], yml: Buffer, sig: Buffer}}
 */
function collectRelease(dir) {
    const read = (name) => {
        const file = path.join(dir, name);
        if (!fs.existsSync(file) || !fs.statSync(file).isFile()) {
            throw new InputError(`${file} not found: nothing to upload`);
        }
        return fs.readFileSync(file);
    };
    const yml = read('latest.yml');
    const sig = read('latest.yml.sig');
    const text = yml.toString('utf8');
    const unquote = (value) => value.trim().replace(/^(['"])(.*)\1$/, '$2');
    const version = unquote((text.match(/^version:(.+)$/m) ?? [])[1] ?? '');
    if (!/^[0-9A-Za-z][0-9A-Za-z.+-]*$/.test(version)) {
        throw new InputError('latest.yml has no usable "version"');
    }
    const urls = [...text.matchAll(/^\s+-\s+url:(.+)$/gm)].map((m) => unquote(m[1]));
    if (urls.length === 0) {
        throw new InputError('latest.yml names no files[].url: nothing to upload');
    }
    const names = [];
    for (const url of urls) {
        if (url !== path.basename(url) || url === '' || url === '.' || url === '..') {
            throw new InputError(`latest.yml names a file outside the release directory: ${url}`);
        }
        names.push(url);
        if (fs.existsSync(path.join(dir, `${url}.blockmap`))) {
            names.push(`${url}.blockmap`);
        }
    }
    const files = [...new Set(names)].map((name) => ({ name, rel: `${version}/${name}`, data: read(name) }));
    return { version, files, yml, sig };
}

function ftpUrl(mirror, rel) {
    const implicit = mirror.protocol === 'ftps';
    const port = mirror.port ?? (implicit ? 990 : 21);
    const segments = [...mirror.dir.split('/'), ...rel.split('/')].filter((s) => s !== '').map(encodeURIComponent);
    return `${implicit ? 'ftps' : 'ftp'}://${mirror.host}:${port}/${segments.join('/')}`;
}

function publicUrl(mirror, rel) {
    return `${mirror.public_url.replace(/\/+$/, '')}/${rel.split('/').map(encodeURIComponent).join('/')}`;
}

function defaultCurl(args, input) {
    const result = spawnSync('curl', args, { input, encoding: 'utf8', maxBuffer: 1 << 20 });
    return { status: result.status ?? 1, stderr: result.error ? result.error.message : result.stderr };
}

/**
 * Credentials go through a curl config on stdin, not through argv visible in the process list.
 */
function upload(curl, mirror, rel, localFile) {
    const quote = (value) => `"${String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`;
    const args = ['--fail', '--silent', '--show-error', '--ssl-reqd', '--ftp-create-dirs', '--connect-timeout', '30', '--config', '-', '--upload-file', localFile, ftpUrl(mirror, rel)];
    const result = curl(args, `user = ${quote(`${mirror.user}:${mirror.password}`)}\n`);
    if (result.status !== 0) {
        throw new Error(`curl exit ${result.status} for ${rel}: ${String(result.stderr ?? '').trim().split('\n').pop()}`);
    }
}

async function retry(fn, sleep, attempts, backoffMs) {
    let lastError;
    for (let attempt = 0; attempt < attempts; attempt++) {
        if (attempt > 0) {
            await sleep(backoffMs * 2 ** (attempt - 1));
        }
        try {
            return await fn();
        } catch (e) {
            lastError = e;
        }
    }
    throw lastError;
}

async function fetchOk(fetchImpl, url, init) {
    const response = await fetchImpl(url, { cache: 'no-store', ...init });
    if (response.status !== 200) {
        throw new Error(`HTTP ${response.status} for ${url}`);
    }
    return response;
}

async function verifyMirror(fetchImpl, mirror, release) {
    for (const [rel, data] of [['latest.yml', release.yml], ['latest.yml.sig', release.sig]]) {
        const body = Buffer.from(await (await fetchOk(fetchImpl, publicUrl(mirror, rel))).arrayBuffer());
        if (!body.equals(data)) {
            throw new Error(`${publicUrl(mirror, rel)} differs from the uploaded bytes`);
        }
    }
    for (const file of release.files) {
        const url = publicUrl(mirror, file.rel);
        const size = Number((await fetchOk(fetchImpl, url, { method: 'HEAD' })).headers.get('content-length'));
        if (size !== file.data.length) {
            throw new Error(`${url} has size ${size}, expected ${file.data.length}`);
        }
    }
}

async function publishTo(id, mirror, release, dir, deps) {
    const { curl, fetch: fetchImpl, sleep, attempts, backoffMs } = deps;
    const steps = [
        ...release.files.map((file) => [file.rel, path.join(dir, file.name)]),
        ['latest.yml.sig', path.join(dir, 'latest.yml.sig')],
        ['latest.yml', path.join(dir, 'latest.yml')],
    ];
    for (const [rel, local] of steps) {
        await retry(async () => upload(curl, mirror, rel, local), sleep, attempts, backoffMs);
    }
    await retry(() => verifyMirror(fetchImpl, mirror, release), sleep, attempts, backoffMs);
    console.log(`Mirror ${id}: ${release.files.length} file(s) of ${release.version}, latest.yml.sig and latest.yml uploaded and verified.`);
}

async function main(argv = process.argv.slice(2), env = process.env, overrides = {}) {
    if (argv.length > 1) {
        console.error('Usage: publish-update-mirror.js [release-dir]');
        return 2;
    }
    const deps = { curl: defaultCurl, fetch: globalThis.fetch, sleep: (ms) => new Promise((resolve) => setTimeout(resolve, ms)), attempts: ATTEMPTS, backoffMs: BACKOFF_MS, ...overrides };
    const dir = path.resolve(argv[0] ?? 'dist');
    let creds;
    let release;
    try {
        creds = parseCreds(env.MIRROR_CREDS);
        release = collectRelease(dir);
    } catch (e) {
        if (!(e instanceof InputError)) {
            throw e;
        }
        console.error(`::error::${e.message}`);
        return 1;
    }
    for (const [id, mirror] of Object.entries(creds)) {
        try {
            await publishTo(id, mirror, release, dir, deps);
        } catch (e) {
            // The credentials are not in the message: curl gets them on stdin.
            console.log(`::warning::mirror ${id} was not updated: ${e.message}`);
        }
    }
    return 0;
}

if (require.main === module) {
    main().then((code) => {
        process.exitCode = code;
    });
}

module.exports = { main, parseCreds, collectRelease, ftpUrl, publicUrl };
