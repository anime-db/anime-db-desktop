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
    getStatePath: jest.fn(() => '/fake/userData/state.json'),
}));

const fs = require('fs');
const paths = require('../../native/paths');
const {
    beginStartAttempt, hasModeChanged, commitStartSuccess, commitDiagnosedFailure,
} = require('../../native/supervisor/safe-mode');

let stateOnDisk;

beforeEach(() => {
    stateOnDisk = undefined;
    jest.spyOn(fs, 'readFileSync').mockImplementation(() => {
        if (stateOnDisk === undefined) throw Object.assign(new Error('ENOENT'), { code: 'ENOENT' });
        return stateOnDisk;
    });
    jest.spyOn(fs, 'writeFileSync').mockImplementation((_, contents) => { stateOnDisk = contents; });
    jest.spyOn(fs, 'mkdirSync').mockImplementation(() => {});
});

afterEach(() => {
    jest.restoreAllMocks();
});

describe('beginStartAttempt', () => {
    test('first run ever (no state.json) does not prompt and marks the start pending', () => {
        expect(beginStartAttempt()).toBe(false);

        expect(paths.getStatePath).toHaveBeenCalled();
        const written = JSON.parse(stateOnDisk);
        expect(written.startPending).toBe(true);
        expect(written.unclosedStartStreak).toBe(0);
    });

    test('a corrupt state.json is treated like a first run, not fatal', () => {
        stateOnDisk = 'not json';
        expect(() => beginStartAttempt()).not.toThrow();
        expect(beginStartAttempt()).toBe(false);
    });

    test('one unclosed marker from the previous run does not prompt yet', () => {
        stateOnDisk = JSON.stringify({ startPending: true, unclosedStartStreak: 0 });

        expect(beginStartAttempt()).toBe(false);

        expect(JSON.parse(stateOnDisk).unclosedStartStreak).toBe(1);
    });

    test('two unclosed markers in a row prompts for safe mode', () => {
        stateOnDisk = JSON.stringify({ startPending: true, unclosedStartStreak: 1 });

        expect(beginStartAttempt()).toBe(true);

        expect(JSON.parse(stateOnDisk).unclosedStartStreak).toBe(2);
    });

    test('a cleanly closed previous run (startPending: false) resets the streak', () => {
        stateOnDisk = JSON.stringify({ startPending: false, unclosedStartStreak: 5 });

        expect(beginStartAttempt()).toBe(false);

        expect(JSON.parse(stateOnDisk).unclosedStartStreak).toBe(0);
    });

    test('merges into existing state.json content instead of overwriting other fields', () => {
        stateOnDisk = JSON.stringify({ someOtherField: 'keep-me' });

        beginStartAttempt();

        expect(JSON.parse(stateOnDisk).someOtherField).toBe('keep-me');
    });
});

describe('hasModeChanged', () => {
    test('reports no change on a first run (no stored safeMode) when starting normally', () => {
        expect(hasModeChanged(false)).toBe(false);
    });

    test('reports a change on a first run when starting in safe mode', () => {
        expect(hasModeChanged(true)).toBe(true);
    });

    test('reports a change when switching from normal to safe mode', () => {
        stateOnDisk = JSON.stringify({ safeMode: false });
        expect(hasModeChanged(true)).toBe(true);
    });

    test('reports a change when switching from safe mode back to normal', () => {
        stateOnDisk = JSON.stringify({ safeMode: true });
        expect(hasModeChanged(false)).toBe(true);
    });

    test('reports no change when the mode matches the last successful start', () => {
        stateOnDisk = JSON.stringify({ safeMode: true });
        expect(hasModeChanged(true)).toBe(false);
    });
});

describe('commitStartSuccess', () => {
    test('clears the pending marker, resets the streak and records the mode', () => {
        stateOnDisk = JSON.stringify({ startPending: true, unclosedStartStreak: 2 });

        commitStartSuccess(true);

        const written = JSON.parse(stateOnDisk);
        expect(written.startPending).toBe(false);
        expect(written.unclosedStartStreak).toBe(0);
        expect(written.safeMode).toBe(true);
    });

    test('merges into existing state.json content instead of overwriting other fields', () => {
        stateOnDisk = JSON.stringify({ buildFingerprint: 'keep-me' });

        commitStartSuccess(false);

        expect(JSON.parse(stateOnDisk).buildFingerprint).toBe('keep-me');
    });
});

describe('commitDiagnosedFailure', () => {
    test('clears the pending marker and resets the streak without touching the committed mode', () => {
        stateOnDisk = JSON.stringify({ startPending: true, unclosedStartStreak: 2, safeMode: false });

        commitDiagnosedFailure();

        const written = JSON.parse(stateOnDisk);
        expect(written.startPending).toBe(false);
        expect(written.unclosedStartStreak).toBe(0);
        expect(written.safeMode).toBe(false);
    });

    test('a diagnosed failure does not carry over into the next launch\'s streak', () => {
        stateOnDisk = JSON.stringify({ startPending: true, unclosedStartStreak: 1 });

        commitDiagnosedFailure();

        expect(beginStartAttempt()).toBe(false);
        expect(JSON.parse(stateOnDisk).unclosedStartStreak).toBe(0);
    });

    test('merges into existing state.json content instead of overwriting other fields', () => {
        stateOnDisk = JSON.stringify({ buildFingerprint: 'keep-me' });

        commitDiagnosedFailure();

        expect(JSON.parse(stateOnDisk).buildFingerprint).toBe('keep-me');
    });
});
