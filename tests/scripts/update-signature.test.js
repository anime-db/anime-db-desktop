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

const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { parseKeys, verifySignature } = require('../../native/updater/signature');
const { checkUpdateKeys } = require('../../scripts/check-packaged-app');
const republish = require('../../scripts/check-release-republish');

const root = path.resolve(__dirname, '..', '..');
const keysFile = path.join(root, 'native', 'updater', 'update-keys.json');
const vectorDir = path.join(root, 'tests', 'fixtures', 'update-keys');
const keys = parseKeys(JSON.parse(fs.readFileSync(keysFile, 'utf8')));
const vector = fs.readFileSync(path.join(vectorDir, 'backup-vector.txt'));
const vectorSig = fs.readFileSync(path.join(vectorDir, 'backup-vector.txt.sig'), 'utf8');

let tmp;
beforeEach(() => {
    tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'update-sig-'));
});
afterEach(() => {
    fs.rmSync(tmp, { recursive: true, force: true });
});

function script(name, args, env = {}) {
    const result = spawnSync(process.execPath, [path.join(root, 'scripts', name), ...args], { encoding: 'utf8', env: { PATH: process.env.PATH, ...env } });
    return { code: result.status, out: result.stdout + result.stderr };
}

function keyPair() {
    const { publicKey, privateKey } = crypto.generateKeyPairSync('ed25519');
    return {
        pem: privateKey.export({ type: 'pkcs8', format: 'pem' }),
        raw: publicKey.export({ type: 'spki', format: 'der' }).subarray(-32).toString('base64'),
    };
}

describe('update-keys.json and the backup vector', () => {
    test('holds the working and the backup key, 32 bytes each', () => {
        expect(JSON.parse(fs.readFileSync(keysFile, 'utf8'))).toEqual(['QAu6qjrto2ZT7ZMXP1In13n6CGZrhzKYA1o2vEW0TNY=', 'ON+3KANtg/asq7moZKsRQOtgK1krf3/jYwbvjuXDb9g=']);
        expect(keys.map((key) => key.length)).toEqual([32, 32]);
    });

    test('the vector is exact bytes: 31, no trailing newline', () => {
        expect(vector.toString()).toBe('animedb-update-backup-vector-v1');
        expect(vector.length).toBe(31);
    });

    test('verifies with the backup key and not with the working one', () => {
        expect(verifySignature(vector, vectorSig, [keys[1]])).toBe(true);
        expect(verifySignature(vector, vectorSig, [keys[0]])).toBe(false);
        expect(verifySignature(vector, vectorSig, keys)).toBe(true);
    });

    test('rejects a malformed signature instead of throwing', () => {
        expect(verifySignature(vector, 'not base64!', keys)).toBe(false);
        expect(verifySignature(vector, Buffer.alloc(10).toString('base64'), keys)).toBe(false);
    });
});

describe('signature.js', () => {
    test('requires nothing but node:crypto', () => {
        const source = fs.readFileSync(path.join(root, 'native', 'updater', 'signature.js'), 'utf8');
        expect([...source.matchAll(/require\(([^)]*)\)/g)].map((m) => m[1])).toEqual(["'node:crypto'"]);
        expect(source).not.toMatch(/^\s*import /m);
    });

    test('parseKeys rejects an empty list and keys of the wrong length', () => {
        expect(() => parseKeys([])).toThrow();
        expect(() => parseKeys([Buffer.alloc(31).toString('base64')])).toThrow('31 bytes');
        expect(() => parseKeys(['***'])).toThrow('base64');
    });
});

describe('sign-update.js and verify-update-signature.js', () => {
    function signed(pair, content = 'version: 1.0.0\nsha512: abc\n') {
        const yml = path.join(tmp, 'latest.yml');
        fs.writeFileSync(yml, content);
        const result = script('sign-update.js', [yml], { UPDATE_SIGNING_KEY: pair.pem });
        expect(result.code).toBe(0);
        return yml;
    }

    function trusted(...raws) {
        const file = path.join(tmp, 'keys.json');
        fs.writeFileSync(file, JSON.stringify(raws));
        return file;
    }

    test('writes base64 of 64 bytes plus a newline, accepted when the key is trusted', () => {
        const pair = keyPair();
        const yml = signed(pair);
        const sig = fs.readFileSync(`${yml}.sig`, 'utf8');

        expect(sig.endsWith('\n')).toBe(true);
        expect(Buffer.from(sig.trim(), 'base64').length).toBe(64);
        expect(script('verify-update-signature.js', [yml, `${yml}.sig`, trusted(keyPair().raw, pair.raw)]).code).toBe(0);
    });

    test('the signature is deterministic', () => {
        const pair = keyPair();
        const yml = signed(pair);
        const first = fs.readFileSync(`${yml}.sig`, 'utf8');
        signed(pair);

        expect(fs.readFileSync(`${yml}.sig`, 'utf8')).toBe(first);
    });

    test('rejects a signature by a key that is not in the key file', () => {
        const yml = signed(keyPair());

        expect(script('verify-update-signature.js', [yml, `${yml}.sig`, trusted(keyPair().raw)]).code).toBe(1);
    });

    test('rejects the real key file for a signature by a temporary key', () => {
        const yml = signed(keyPair());

        expect(script('verify-update-signature.js', [yml]).code).toBe(1);
    });

    test('rejects latest.yml with one changed byte', () => {
        const pair = keyPair();
        const yml = signed(pair);
        const file = trusted(pair.raw);
        const bytes = fs.readFileSync(yml);
        bytes[bytes.length - 3] ^= 1;
        fs.writeFileSync(yml, bytes);

        expect(script('verify-update-signature.js', [yml, `${yml}.sig`, file]).code).toBe(1);
    });

    test('fails on a missing signature file and on a missing key', () => {
        const yml = signed(keyPair());
        fs.rmSync(`${yml}.sig`);

        expect(script('verify-update-signature.js', [yml]).code).toBe(1);
        expect(script('sign-update.js', [yml]).code).toBe(1);
    });

    test('refuses a non-Ed25519 signing key', () => {
        const { privateKey } = crypto.generateKeyPairSync('rsa', { modulusLength: 2048 });
        const yml = path.join(tmp, 'latest.yml');
        fs.writeFileSync(yml, 'x');

        expect(script('sign-update.js', [yml], { UPDATE_SIGNING_KEY: privateKey.export({ type: 'pkcs8', format: 'pem' }) }).code).toBe(1);
    });
});

describe('check-release-republish.js', () => {
    const response = (status, body = '') => ({ status, ok: status >= 200 && status < 300, arrayBuffer: async () => Buffer.from(body) });

    let yml;
    beforeEach(() => {
        yml = path.join(tmp, 'latest.yml');
        fs.writeFileSync(yml, 'version: 1.0.0\n');
        jest.spyOn(console, 'log').mockImplementation(() => {});
        jest.spyOn(console, 'error').mockImplementation(() => {});
    });
    afterEach(() => jest.restoreAllMocks());

    test('passes when the release does not exist (404) and asks the public URL', async () => {
        const fetchImpl = jest.fn(async () => response(404));

        expect(await republish.main(['v1.0.0', yml], fetchImpl)).toBe(0);
        expect(fetchImpl.mock.calls[0][0]).toBe('https://github.com/anime-db/anime-db-desktop/releases/download/v1.0.0/latest.yml');
    });

    test('passes on identical bytes', async () => {
        expect(await republish.main(['v1.0.0', yml], async () => response(200, 'version: 1.0.0\n'))).toBe(0);
    });

    test('fails on different bytes', async () => {
        expect(await republish.main(['v1.0.0', yml], async () => response(200, 'version: 1.0.0\nsha512: other\n'))).toBe(1);
    });

    test('does not pass on an unexpected status or a network error', async () => {
        expect(await republish.main(['v1.0.0', yml], async () => response(500))).toBe(2);
        expect(
            await republish.main(['v1.0.0', yml], async () => {
                throw new Error('offline');
            }),
        ).toBe(2);
    });
});

describe('check-update-verifier.js', () => {
    const module = path.join(root, 'native', 'updater', 'signature.js');

    test('passes with the repository module and the vector', () => {
        expect(script('check-update-verifier.js', [module]).code).toBe(0);
    });

    test('fails on a corrupted vector', () => {
        fs.copyFileSync(path.join(vectorDir, 'backup-vector.txt.sig'), path.join(tmp, 'backup-vector.txt.sig'));
        fs.writeFileSync(path.join(tmp, 'backup-vector.txt'), 'animedb-update-backup-vector-v2');

        expect(script('check-update-verifier.js', [module, tmp]).code).toBe(1);
    });

    describe('with a broken key file next to a copy of the module', () => {
        function withKeys(list) {
            fs.copyFileSync(module, path.join(tmp, 'signature.js'));
            fs.writeFileSync(path.join(tmp, 'update-keys.json'), JSON.stringify(list.map((k) => k.toString('base64'))));
            return script('check-update-verifier.js', [path.join(tmp, 'signature.js')]).code;
        }

        test('fails without a backup key', () => {
            expect(withKeys([keys[0]])).toBe(1);
        });

        test('fails when the keys are swapped', () => {
            expect(withKeys([keys[1], keys[0]])).toBe(1);
        });

        test('passes with the right order (control)', () => {
            expect(withKeys([keys[0], keys[1]])).toBe(0);
        });
    });

    test('fails when the module cannot be loaded', () => {
        expect(script('check-update-verifier.js', [path.join(tmp, 'missing.js')]).code).toBe(1);
    });
});

describe('checkUpdateKeys', () => {
    function app(content) {
        const dir = path.join(tmp, 'native', 'updater');
        fs.mkdirSync(dir, { recursive: true });
        fs.copyFileSync(path.join(root, 'native', 'updater', 'signature.js'), path.join(dir, 'signature.js'));
        if (content !== null) {
            fs.writeFileSync(path.join(dir, 'update-keys.json'), content);
        }
        return tmp;
    }

    test('accepts the real key file', () => {
        expect(checkUpdateKeys(app(fs.readFileSync(keysFile, 'utf8')))).toEqual([]);
    });

    test('rejects an empty array, invalid JSON and a missing file', () => {
        expect(checkUpdateKeys(app('[]'))).toHaveLength(1);
        expect(checkUpdateKeys(app('{'))).toHaveLength(1);
        expect(checkUpdateKeys(app(null))).toHaveLength(1);
    });

    test('rejects a key that is not 32 bytes', () => {
        const problems = checkUpdateKeys(app(JSON.stringify([keys[0].toString('base64'), Buffer.alloc(31).toString('base64'), 'zz'])));

        expect(problems).toHaveLength(2);
        expect(problems[0]).toContain('key #1');
    });

    test('rejects an unpadded key that the client would refuse', () => {
        const unpadded = keys[0].toString('base64').replace(/=+$/, '');
        expect(unpadded.length % 4).not.toBe(0);
        expect(checkUpdateKeys(app(JSON.stringify([unpadded])))).toHaveLength(1);
    });
});
