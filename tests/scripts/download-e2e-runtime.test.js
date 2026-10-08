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
 * The whole point of the script is the checksum: it decides whether the E2E set runs on the PHP the
 * user will get or on whatever binary happened to be left in bin/frankenphp from an older pin. That
 * decision has to be checked by tests rather than by one manual run — a flipped comparison would
 * otherwise leave every suite green.
 *
 * The network is mocked (`downloadBufferWithRetry`), the destination is a temp file: the real
 * download is 170 MB and belongs to the release workflow, not to jest.
 */

'use strict';

const crypto = require('crypto');
const fs     = require('fs');
const os     = require('os');
const path   = require('path');

jest.mock('../../scripts/download-bins', () => ({ downloadBufferWithRetry: jest.fn() }));

const { downloadBufferWithRetry } = require('../../scripts/download-bins');
const { main, assetUrl, ASSET_NAME, DEST } = require('../../scripts/download-e2e-runtime');

const repoRoot = path.join(__dirname, '..', '..');
const versions = JSON.parse(fs.readFileSync(path.join(repoRoot, 'scripts', 'versions.json'), 'utf8'));

const PAYLOAD = Buffer.from('a FrankenPHP binary, as far as these tests are concerned');
const PAYLOAD_SHA = crypto.createHash('sha256').update(PAYLOAD).digest('hex');
/** Stand-in for scripts/versions.json: the pin is an input, so a broken pin is a testable state. */
const PINNED = { frankenphp: '9.9.9', sha256: { frankenphpLinux: PAYLOAD_SHA } };

let dir;
let dest;
let platform;

beforeEach(() => {
    dir = fs.mkdtempSync(path.join(os.tmpdir(), 'animedb-runtime-'));
    dest = path.join(dir, 'bin', 'frankenphp', 'frankenphp');
    platform = Object.getOwnPropertyDescriptor(process, 'platform');
    Object.defineProperty(process, 'platform', { value: 'linux' });
    jest.spyOn(console, 'log').mockImplementation(() => {});
    downloadBufferWithRetry.mockReset();
});

afterEach(() => {
    Object.defineProperty(process, 'platform', platform);
    jest.restoreAllMocks();
    fs.rmSync(dir, { recursive: true, force: true });
});

describe('the download target', () => {
    /** The asset name is an upstream contract: a wrong one is a 404, not a wrong binary. */
    test('is the Linux x86-64 asset of the pinned release', () => {
        expect(ASSET_NAME).toBe('frankenphp-linux-x86_64');
        // The version in the URL comes from the pin, not from a literal in the script: a bump of
        // scripts/versions.json must move the download with it.
        expect(assetUrl({ frankenphp: '9.9.9' })).toBe(
            'https://github.com/php/frankenphp/releases/download/v9.9.9/frankenphp-linux-x86_64',
        );
        expect(assetUrl()).toContain(`/v${versions.frankenphp}/`);
        // And the pin itself must carry a checksum, or the real download is unverifiable.
        expect(versions.sha256.frankenphpLinux).toMatch(/^[0-9a-f]{64}$/);
    });

    /** Where prereq.js looks for it; a different path means "binary not found" with a hint to run this script. */
    test('is the path the E2E run expects', () => {
        expect(DEST).toBe(path.join(repoRoot, 'bin', 'frankenphp', 'frankenphp'));
    });
});

describe('download with checksum verification', () => {
    test('writes the binary when the checksum matches, executable', async () => {
        downloadBufferWithRetry.mockResolvedValue(PAYLOAD);

        await main({ dest, pinned: PINNED });

        expect(fs.readFileSync(dest)).toEqual(PAYLOAD);
        // eslint-disable-next-line no-bitwise
        expect(fs.statSync(dest).mode & 0o111).not.toBe(0);
    });

    test('refuses a buffer whose checksum does not match and writes nothing', async () => {
        downloadBufferWithRetry.mockResolvedValue(Buffer.from('a different binary'));

        await expect(main({ dest, pinned: PINNED })).rejects.toThrow(/SHA-256 mismatch/);
        expect(fs.existsSync(dest)).toBe(false);
    });

    test('does not download again when the binary on disk already matches', async () => {
        fs.mkdirSync(path.dirname(dest), { recursive: true });
        fs.writeFileSync(dest, PAYLOAD);

        await main({ dest, pinned: PINNED });

        expect(downloadBufferWithRetry).not.toHaveBeenCalled();
    });

    /**
     * The branch that matters on a version bump: a binary from the previous pin is on disk and its
     * checksum no longer matches. Accepting it would run the whole set on the wrong PHP (issue #536).
     */
    test('replaces a binary left over from an older pin', async () => {
        fs.mkdirSync(path.dirname(dest), { recursive: true });
        fs.writeFileSync(dest, Buffer.from('the binary of the previous pin'));
        downloadBufferWithRetry.mockResolvedValue(PAYLOAD);

        await main({ dest, pinned: PINNED });

        expect(downloadBufferWithRetry).toHaveBeenCalledTimes(1);
        expect(fs.readFileSync(dest)).toEqual(PAYLOAD);
    });
});

describe('refusals before any download', () => {
    test.each([
        ['missing', { frankenphp: '9.9.9', sha256: {} }],
        ['empty', { frankenphp: '9.9.9', sha256: { frankenphpLinux: '' } }],
    ])('a sha256.frankenphpLinux that is %s is refused', async (_name, pinned) => {
        await expect(main({ dest, pinned })).rejects.toThrow(/sha256.frankenphpLinux/);
        expect(downloadBufferWithRetry).not.toHaveBeenCalled();
    });

    /** The Windows runtime is a different set of files and comes from download-bins.js. */
    test('a non-Linux platform is refused', async () => {
        Object.defineProperty(process, 'platform', { value: 'win32' });

        await expect(main({ dest, pinned: PINNED })).rejects.toThrow(/only usable on Linux/);
        expect(downloadBufferWithRetry).not.toHaveBeenCalled();
    });
});
