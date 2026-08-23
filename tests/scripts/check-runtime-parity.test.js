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

const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');

jest.mock('child_process', () => ({ execFileSync: jest.fn() }));
const { execFileSync } = require('child_process');

const { collectFacts, diffFacts } = require('../../scripts/check-runtime-parity');

const SAMPLE_EXTENSIONS = ['Core', 'date', 'intl', 'mbstring'];
const SAMPLE_ENDONYMS = { en: 'English', ru: 'Русский', de: 'Deutsch', ja: '日本語' };
const SAMPLE_INI = { short_open_tag: '1', 'zend.assertions': '1' };

function sampleFacts(overrides = {}) {
    return {
        frankenphp: { version: '1.12.4', exeSha256: 'a'.repeat(64) },
        extensions: [...SAMPLE_EXTENSIONS],
        icu: { version: '77.1', cldrVersion: '77.1' },
        localeEndonyms: { ...SAMPLE_ENDONYMS },
        ini: { ...SAMPLE_INI },
        ...overrides,
    };
}

describe('diffFacts', () => {
    test('returns no lines when the snapshot matches the collected facts', () => {
        expect(diffFacts(sampleFacts(), sampleFacts())).toEqual([]);
    });

    test('reports missing and unexpected extensions', () => {
        const expected = sampleFacts();
        const actual = sampleFacts({ extensions: ['Core', 'date', 'mbstring', 'pcntl'] });

        const diff = diffFacts(expected, actual);

        expect(diff).toContainEqual(expect.stringContaining('extensions: missing ["intl"]'));
        expect(diff).toContainEqual(expect.stringContaining('extensions: unexpected ["pcntl"]'));
    });

    test('reports an ICU version mismatch', () => {
        const expected = sampleFacts();
        const actual = sampleFacts({ icu: { version: '76.1', cldrVersion: '76.1' } });

        const diff = diffFacts(expected, actual);

        expect(diff).toContainEqual('icu.version: expected "77.1", got "76.1"');
        expect(diff).toContainEqual('icu.cldrVersion: expected "77.1", got "76.1"');
    });

    test('reports a locale endonym mismatch', () => {
        const expected = sampleFacts();
        const actual = sampleFacts({ localeEndonyms: { ...SAMPLE_ENDONYMS, de: 'German' } });

        expect(diffFacts(expected, actual)).toContainEqual('localeEndonyms.de: expected "Deutsch", got "German"');
    });

    test('reports an ini default mismatch', () => {
        const expected = sampleFacts();
        const actual = sampleFacts({ ini: { ...SAMPLE_INI, short_open_tag: '0' } });

        expect(diffFacts(expected, actual)).toContainEqual('ini.short_open_tag: expected "1", got "0"');
    });
});

describe('collectFacts', () => {
    let tmpDir;
    let binaryPath;

    beforeEach(() => {
        tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'check-runtime-parity-test-'));
        binaryPath = path.join(tmpDir, 'frankenphp.exe');
        fs.writeFileSync(binaryPath, 'fake-frankenphp-binary-bytes');
        execFileSync.mockReset();
    });

    afterEach(() => {
        fs.rmSync(tmpDir, { recursive: true, force: true });
    });

    test('throws a clear message instead of an unhandled exception when the binary is missing', () => {
        const missingPath = path.join(tmpDir, 'does-not-exist.exe');

        expect(() => collectFacts(missingPath)).toThrow(/binary not found.*download-bins/s);
        expect(execFileSync).not.toHaveBeenCalled();
    });

    test('invokes the binary via the php-cli subcommand, never directly', () => {
        execFileSync.mockImplementation((command, args) => {
            const code = args[args.indexOf('-r') + 1];
            if (code.includes('get_loaded_extensions')) return SAMPLE_EXTENSIONS.join('\n');
            if (code.includes('INTL_ICU_VERSION')) return '77.1|77.1';
            if (code.includes('Locale::getDisplayName')) return JSON.stringify(SAMPLE_ENDONYMS);
            if (code.includes('ini_get')) return JSON.stringify(SAMPLE_INI);
            throw new Error(`unexpected php-cli invocation: ${code}`);
        });

        collectFacts(binaryPath);

        expect(execFileSync).toHaveBeenCalled();
        for (const [command, args] of execFileSync.mock.calls) {
            expect(command).toBe(binaryPath);
            expect(args[0]).toBe('php-cli');
            expect(args[1]).toBe('-r');
        }
    });

    test('assembles the collected facts from the binary output, sorted and parsed', () => {
        execFileSync.mockImplementation((command, args) => {
            const code = args[args.indexOf('-r') + 1];
            if (code.includes('get_loaded_extensions')) return 'mbstring\nCore\ndate\nintl\n';
            if (code.includes('INTL_ICU_VERSION')) return '77.1|77.1\n';
            if (code.includes('Locale::getDisplayName')) return `${JSON.stringify(SAMPLE_ENDONYMS)}\n`;
            if (code.includes('ini_get')) return `${JSON.stringify(SAMPLE_INI)}\n`;
            throw new Error(`unexpected php-cli invocation: ${code}`);
        });

        const facts = collectFacts(binaryPath);

        expect(facts.frankenphp).toEqual({
            version: '1.12.4',
            exeSha256: crypto.createHash('sha256').update(fs.readFileSync(binaryPath)).digest('hex'),
        });
        expect(facts.extensions).toEqual(['Core', 'date', 'intl', 'mbstring']);
        expect(facts.icu).toEqual({ version: '77.1', cldrVersion: '77.1' });
        expect(facts.localeEndonyms).toEqual(SAMPLE_ENDONYMS);
        expect(facts.ini).toEqual(SAMPLE_INI);
    });
});
