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

const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { main, parseCreds, ftpUrl } = require('../../scripts/publish-update-mirror');

const source = fs.readFileSync(path.resolve(__dirname, '..', '..', 'scripts', 'publish-update-mirror.js'), 'utf8');
const YML = 'version: 1.2.3\nfiles:\n  - url: AnimeDB-Setup-1.2.3.exe\n    sha512: x\npath: AnimeDB-Setup-1.2.3.exe\n';
const mirror = (name) => ({ host: `ftp.${name}.test`, user: `u-${name}`, password: `p-${name}`, dir: '/app', public_url: `https://${name}.test/app` });

let dir;
let logs;
beforeEach(() => {
    dir = fs.mkdtempSync(path.join(os.tmpdir(), 'mirror-'));
    fs.writeFileSync(path.join(dir, 'latest.yml'), YML);
    fs.writeFileSync(path.join(dir, 'latest.yml.sig'), 'c2ln\n');
    fs.writeFileSync(path.join(dir, 'AnimeDB-Setup-1.2.3.exe'), 'installer-bytes');
    fs.writeFileSync(path.join(dir, 'AnimeDB-Setup-1.2.3.exe.blockmap'), 'bm');
    logs = [];
    jest.spyOn(console, 'log').mockImplementation((m) => logs.push(String(m)));
    jest.spyOn(console, 'error').mockImplementation((m) => logs.push(String(m)));
});
afterEach(() => {
    jest.restoreAllMocks();
    fs.rmSync(dir, { recursive: true, force: true });
});

/** A fake web side serving what the fake curl "uploaded". */
function fakes({ failUpload = () => false, serve = null } = {}) {
    const uploaded = new Map();
    const calls = [];
    const curl = jest.fn((args, input) => {
        const url = args[args.length - 1];
        const file = args[args.indexOf('--upload-file') + 1];
        calls.push({ args, input, url });
        if (failUpload(url)) {
            return { status: 7, stderr: 'curl: (7) failed' };
        }
        uploaded.set(url, fs.readFileSync(file));
        return { status: 0, stderr: '' };
    });
    const fetchImpl = jest.fn(async (url) => {
        const target = new URL(url);
        const key = `ftp://ftp.${target.host.replace('.test', '')}.test:21${target.pathname}`;
        const data = serve ? serve(url, uploaded) : uploaded.get(key) ?? null;
        if (data === null) {
            return { status: 404, headers: new Map(), arrayBuffer: async () => new ArrayBuffer(0) };
        }
        return { status: 200, headers: new Map([['content-length', String(data.length)]]), arrayBuffer: async () => data };
    });
    return { curl, fetch: fetchImpl, calls, uploaded };
}

const run = (creds, f, argv = [dir]) => main(argv, { MIRROR_CREDS: JSON.stringify(creds) }, { curl: f.curl, fetch: f.fetch, sleep: async () => {}, attempts: 2, backoffMs: 0 });

describe('publish-update-mirror.js', () => {
    test('uploads to every mirror in order: version files, signature, latest.yml', async () => {
        const f = fakes();

        expect(await run({ a: mirror('a'), b: mirror('b') }, f)).toBe(0);

        const paths = f.calls.map((c) => c.url);
        expect(paths).toEqual([
            'ftp://ftp.a.test:21/app/1.2.3/AnimeDB-Setup-1.2.3.exe',
            'ftp://ftp.a.test:21/app/1.2.3/AnimeDB-Setup-1.2.3.exe.blockmap',
            'ftp://ftp.a.test:21/app/latest.yml.sig',
            'ftp://ftp.a.test:21/app/latest.yml',
            'ftp://ftp.b.test:21/app/1.2.3/AnimeDB-Setup-1.2.3.exe',
            'ftp://ftp.b.test:21/app/1.2.3/AnimeDB-Setup-1.2.3.exe.blockmap',
            'ftp://ftp.b.test:21/app/latest.yml.sig',
            'ftp://ftp.b.test:21/app/latest.yml',
        ]);
        expect(logs.filter((l) => l.startsWith('::warning'))).toEqual([]);
    });

    test('requires TLS and keeps the password out of argv', async () => {
        const f = fakes();
        await run({ a: mirror('a') }, f);

        for (const call of f.calls) {
            expect(call.args).toEqual(expect.arrayContaining(['--ssl-reqd', '--ftp-create-dirs']));
            expect(call.args.join(' ')).not.toContain('p-a');
            expect(call.input).toBe('user = "u-a:p-a"\n');
        }
    });

    test('never disables certificate verification', () => {
        expect(source).not.toMatch(/--insecure|(^|[\s'"`])-k([\s'"`]|$)/);
    });

    test('verifies over the public URL', async () => {
        const f = fakes();
        await run({ a: mirror('a') }, f);

        expect(f.fetch.mock.calls.map((c) => c[0])).toEqual([
            'https://a.test/app/latest.yml',
            'https://a.test/app/latest.yml.sig',
            'https://a.test/app/1.2.3/AnimeDB-Setup-1.2.3.exe',
            'https://a.test/app/1.2.3/AnimeDB-Setup-1.2.3.exe.blockmap',
        ]);
    });

    test('an upload failure after retries is a warning, exit 0, and latest.yml is not uploaded', async () => {
        const f = fakes({ failUpload: (url) => url.endsWith('.exe') });

        expect(await run({ a: mirror('a') }, f)).toBe(0);
        expect(logs.some((l) => l.startsWith('::warning'))).toBe(true);
        expect(f.calls.map((c) => c.url)).not.toContain('ftp://ftp.a.test:21/app/latest.yml');
        expect(logs.join('\n')).not.toContain('p-a');
    });

    test('a failed mirror does not stop the next one', async () => {
        const f = fakes({ failUpload: (url) => url.includes('ftp.a.test') });

        expect(await run({ a: mirror('a'), b: mirror('b') }, f)).toBe(0);
        expect(f.calls.map((c) => c.url)).toContain('ftp://ftp.b.test:21/app/latest.yml');
    });

    test('a verification mismatch after retries is a warning, exit 0', async () => {
        const f = fakes({ serve: () => Buffer.from('stale') });

        expect(await run({ a: mirror('a') }, f)).toBe(0);
        expect(logs.some((l) => l.startsWith('::warning'))).toBe(true);
    });

    test('retries the check while the web side lags', async () => {
        const f = fakes();
        const real = f.fetch.getMockImplementation();
        let first = true;
        f.fetch.mockImplementation(async (...args) => {
            if (first) {
                first = false;
                return { status: 404, headers: new Map(), arrayBuffer: async () => new ArrayBuffer(0) };
            }
            return real(...args);
        });

        expect(await run({ a: mirror('a') }, f)).toBe(0);
        expect(logs.some((l) => l.startsWith('::warning'))).toBe(false);
    });

    test('exits non-zero on invalid MIRROR_CREDS', async () => {
        const f = fakes();

        expect(await main([dir], { MIRROR_CREDS: 'nope' }, { curl: f.curl })).toBe(1);
        expect(await main([dir], {}, { curl: f.curl })).toBe(1);
        expect(await run({}, f)).toBe(1);
        expect(await run({ a: { ...mirror('a'), password: undefined } }, f)).toBe(1);
        expect(await run({ a: { ...mirror('a'), public_url: 'http://a.test' } }, f)).toBe(1);
        expect(f.curl).not.toHaveBeenCalled();
    });

    test('exits non-zero when there is nothing to upload', async () => {
        const f = fakes();
        fs.rmSync(path.join(dir, 'AnimeDB-Setup-1.2.3.exe'));
        expect(await run({ a: mirror('a') }, f)).toBe(1);

        fs.rmSync(path.join(dir, 'latest.yml'));
        expect(await run({ a: mirror('a') }, f)).toBe(1);
        expect(f.curl).not.toHaveBeenCalled();
    });

    test('parseCreds and ftpUrl: implicit TLS and port', () => {
        const creds = parseCreds(JSON.stringify({ a: { ...mirror('a'), protocol: 'ftps', port: 2121 } }));

        expect(ftpUrl(creds.a, '1.2.3/x y.exe')).toBe('ftps://ftp.a.test:2121/app/1.2.3/x%20y.exe');
        expect(() => parseCreds(JSON.stringify({ a: { ...mirror('a'), protocol: 'http' } }))).toThrow('protocol');
    });

    test('parseCreds rejects line breaks in credentials and a malformed port', () => {
        const parse = (patch) => () => parseCreds(JSON.stringify({ a: { ...mirror('a'), ...patch } }));

        expect(parse({ password: 'p\nurl = "x"' })).toThrow('line breaks');
        expect(parse({ user: 'u\r' })).toThrow('line breaks');
        for (const port of ['', ' ', true, null, -1, 0, 65536, 1.5, 'abc']) {
            expect(parse({ port })).toThrow('port');
        }
        expect(parse({ port: '2121' })()).toBeDefined();
    });
});
