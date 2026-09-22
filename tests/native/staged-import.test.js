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

jest.mock('../../native/paths', () => ({
    getImportStagingDir:    jest.fn(() => '/fake/userData/import-staging'),
    getImportRejectionPath: jest.fn(() => '/fake/userData/import-rejected.json'),
}));

const mockRun = jest.fn();
jest.mock('../../native/supervisor/php-command', () => ({
    run:        (...args) => mockRun(...args),
    killOrphan: jest.fn(() => Promise.resolve()),
}));

const mockCheckDumpSchema = jest.fn();
jest.mock('../../native/supervisor/migrations', () => ({
    checkDumpSchema:  (...args) => mockCheckDumpSchema(...args),
    DumpSchemaVerdict: { COMPATIBLE: 'compatible', REJECT: 'reject', CHECK_FAILED: 'check-failed' },
}));

const fs = require('fs');
const { decide, killOrphan, Verdict, RejectReason } = require('../../native/supervisor/staged-import');

const CONTEXT = { qbittorrentPort: 9000, meiliPort: 7700, meiliKey: 'k' };
const FRESH_STAGED_AT = '2026-09-22T00:00:00+00:00'; // "now" for this suite, per Date.now() below
const STALE_STAGED_AT = '2026-09-20T00:00:00+00:00'; // more than 24h before FRESH_STAGED_AT

beforeEach(() => {
    jest.clearAllMocks();
    jest.spyOn(Date, 'now').mockReturnValue(new Date(FRESH_STAGED_AT).getTime());
    jest.spyOn(fs, 'existsSync').mockReturnValue(true);
    jest.spyOn(fs, 'mkdirSync').mockImplementation(() => {});
    jest.spyOn(fs, 'writeFileSync').mockImplementation(() => {});
    jest.spyOn(fs, 'rmSync').mockImplementation(() => {});
    mockCheckDumpSchema.mockResolvedValue('compatible');
});

afterEach(() => {
    jest.restoreAllMocks();
});

function mockValidMarkerStatus(stagedAt = FRESH_STAGED_AT, sourceArchive = 'catalog-export.zip') {
    mockRun.mockResolvedValue({ code: 0, stdout: JSON.stringify({ stagedAt, sourceArchive }), stderr: '' });
}

describe('decide()', () => {
    // Acceptance criterion 1: staging absent → skip, only clearing any stale rejection reason.
    test('returns skip and clears any stale rejection reason when the staging directory does not exist', async () => {
        fs.existsSync.mockReturnValue(false);

        const result = await decide(CONTEXT);

        expect(result).toEqual({ verdict: Verdict.SKIP });
        expect(mockRun).not.toHaveBeenCalled();
        expect(mockCheckDumpSchema).not.toHaveBeenCalled();
        expect(fs.rmSync).toHaveBeenCalledWith('/fake/userData/import-rejected.json', { force: true });
        expect(fs.rmSync).toHaveBeenCalledTimes(1);
    });

    // Acceptance criterion 2: an invalid/unparseable marker rejects, removing import-staging/
    // entirely, and the reason is recorded for /settings/backup — but never touches data.db,
    // which is asserted at the module boundary here: staged-import.js never requires or opens the
    // working database file at all, only rm's import-staging/ and writes the rejection JSON.
    test('rejects with invalid_marker and removes staging entirely when the status command exits non-zero', async () => {
        mockRun.mockResolvedValue({ code: 1, stdout: '', stderr: '' });

        const result = await decide(CONTEXT);

        expect(result).toEqual({ verdict: Verdict.REJECT, reason: RejectReason.INVALID_MARKER });
        expect(mockCheckDumpSchema).not.toHaveBeenCalled();
        expect(fs.rmSync).toHaveBeenCalledWith('/fake/userData/import-staging', { recursive: true, force: true });
        expect(fs.writeFileSync).toHaveBeenCalledWith(
            '/fake/userData/import-rejected.json',
            JSON.stringify({ reason: 'invalid_marker' }),
        );
    });

    test('rejects with invalid_marker when the status command exits 0 but stdout is not valid JSON', async () => {
        mockRun.mockResolvedValue({ code: 0, stdout: 'not json', stderr: '' });

        const result = await decide(CONTEXT);

        expect(result).toEqual({ verdict: Verdict.REJECT, reason: RejectReason.INVALID_MARKER });
        expect(mockCheckDumpSchema).not.toHaveBeenCalled();
    });

    // Acceptance criteria 4 and 5: checkDumpSchema() is asked about the staged file, not the
    // working database, and a reject/check-failed verdict there rejects the staged import too —
    // regardless of the marker being valid.
    test('checks the schema of the staged data.db, not the working database', async () => {
        mockValidMarkerStatus();

        await decide(CONTEXT);

        expect(mockCheckDumpSchema).toHaveBeenCalledWith('/fake/userData/import-staging/data.db', CONTEXT);
    });

    test.each([['reject'], ['check-failed']])('rejects with incompatible_schema when checkDumpSchema() reports %s', async (verdict) => {
        mockValidMarkerStatus();
        mockCheckDumpSchema.mockResolvedValue(verdict);

        const result = await decide(CONTEXT);

        expect(result).toEqual({ verdict: Verdict.REJECT, reason: RejectReason.INCOMPATIBLE_SCHEMA });
        expect(fs.rmSync).toHaveBeenCalledWith('/fake/userData/import-staging', { recursive: true, force: true });
        expect(fs.writeFileSync).toHaveBeenCalledWith(
            '/fake/userData/import-rejected.json',
            JSON.stringify({ reason: 'incompatible_schema' }),
        );
    });

    // Acceptance criterion 6: fresh (< 24h) + valid marker + compatible schema → apply, without
    // ever asking the confirmation callback.
    test('applies a fresh, valid, schema-compatible staged import without asking for confirmation', async () => {
        mockValidMarkerStatus(FRESH_STAGED_AT);
        const confirmStaleImport = jest.fn();

        const result = await decide(CONTEXT, confirmStaleImport);

        expect(result).toEqual({ verdict: Verdict.APPLY });
        expect(confirmStaleImport).not.toHaveBeenCalled();
        // An apply verdict clears any rejection reason a previous decision left behind, but must
        // never remove import-staging/ itself — that belongs to the not-yet-built apply step.
        expect(fs.rmSync).toHaveBeenCalledWith('/fake/userData/import-rejected.json', { force: true });
        expect(fs.rmSync).not.toHaveBeenCalledWith('/fake/userData/import-staging', expect.anything());
    });

    // Acceptance criterion 7: the confirmation is asked only after the schema check passed, with
    // stagedAt and sourceArchive — this is the property that must survive: reordering the schema
    // check after the confirmation would ask the user about an import that is about to be
    // rejected anyway.
    describe('staging older than 24 hours', () => {
        test('asks for confirmation only after the schema check passed, with stagedAt and sourceArchive', async () => {
            mockValidMarkerStatus(STALE_STAGED_AT, 'old-catalog.zip');
            const callOrder = [];
            mockCheckDumpSchema.mockImplementation(() => {
                callOrder.push('checkDumpSchema');
                return Promise.resolve('compatible');
            });
            const confirmStaleImport = jest.fn(() => {
                callOrder.push('confirmStaleImport');
                return true;
            });

            await decide(CONTEXT, confirmStaleImport);

            expect(callOrder).toEqual(['checkDumpSchema', 'confirmStaleImport']);
            expect(confirmStaleImport).toHaveBeenCalledWith({ stagedAt: STALE_STAGED_AT, sourceArchive: 'old-catalog.zip' });
        });

        // Acceptance criteria 9 and 10: confirming applies, via a stubbed callback, no Electron
        // involved.
        test('applies when confirmStaleImport resolves true', async () => {
            mockValidMarkerStatus(STALE_STAGED_AT);

            const result = await decide(CONTEXT, () => true);

            expect(result).toEqual({ verdict: Verdict.APPLY });
            expect(fs.rmSync).toHaveBeenCalledWith('/fake/userData/import-rejected.json', { force: true });
            expect(fs.rmSync).not.toHaveBeenCalledWith('/fake/userData/import-staging', expect.anything());
        });

        // Acceptance criteria 8 and 10: declining rejects and removes staging, via a stubbed
        // callback, no Electron involved.
        test('rejects with user_declined and removes staging when confirmStaleImport resolves false', async () => {
            mockValidMarkerStatus(STALE_STAGED_AT);

            const result = await decide(CONTEXT, () => false);

            expect(result).toEqual({ verdict: Verdict.REJECT, reason: RejectReason.USER_DECLINED });
            expect(fs.rmSync).toHaveBeenCalledWith('/fake/userData/import-staging', { recursive: true, force: true });
            expect(fs.writeFileSync).toHaveBeenCalledWith(
                '/fake/userData/import-rejected.json',
                JSON.stringify({ reason: 'user_declined' }),
            );
        });

        // Fail-closed default: a caller that forgets to supply the callback must not silently
        // apply a stale import.
        test('rejects with user_declined when no confirmStaleImport callback is supplied at all', async () => {
            mockValidMarkerStatus(STALE_STAGED_AT);

            const result = await decide(CONTEXT);

            expect(result).toEqual({ verdict: Verdict.REJECT, reason: RejectReason.USER_DECLINED });
        });

        test('supports an async confirmStaleImport callback', async () => {
            mockValidMarkerStatus(STALE_STAGED_AT);

            const result = await decide(CONTEXT, () => Promise.resolve(true));

            expect(result).toEqual({ verdict: Verdict.APPLY });
        });
    });
});

describe('killOrphan()', () => {
    test('delegates to phpCommand.killOrphan() with the status command name', () => {
        const phpCommand = require('../../native/supervisor/php-command');

        killOrphan();

        expect(phpCommand.killOrphan).toHaveBeenCalledWith('app:import:staged-status');
    });
});
