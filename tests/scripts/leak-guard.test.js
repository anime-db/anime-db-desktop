'use strict';

const fs   = require('fs');
const os   = require('os');
const path = require('path');

const { LEAK_GUARDED_PATHS, snapshotGuardedPaths, findLeakedPaths } = require('../../scripts/leak-guard');

describe('leak guard', () => {
    test('guards the developer data paths, including the cache.app share dir', () => {
        const rel = LEAK_GUARDED_PATHS.map((p) => path.relative(path.resolve(__dirname, '..', '..'), p));
        expect(rel).toEqual(expect.arrayContaining(['data', path.join('app', 'var', 'config.json'), path.join('app', 'var', 'share')]));
    });

    test('reports a guarded path that appeared and ignores untouched ones', () => {
        const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'leak-guard-'));
        try {
            const leaky = path.join(dir, 'share');
            const quiet = path.join(dir, 'quiet');
            fs.writeFileSync(quiet, 'x');
            const before = snapshotGuardedPaths([leaky, quiet]);
            expect(findLeakedPaths(before)).toEqual([]);

            fs.mkdirSync(leaky);
            expect(findLeakedPaths(before)).toEqual([leaky]);
        } finally {
            fs.rmSync(dir, { recursive: true, force: true });
        }
    });
});
