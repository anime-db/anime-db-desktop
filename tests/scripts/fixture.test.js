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

const { execFileSync } = require('child_process');
const fs   = require('fs');
const path = require('path');

const { buildFixture, createIsolatedEnv, disposeFixture } = require('../../scripts/fixture');

const rootDir = path.resolve(__dirname, '..', '..');
const hasApp  = fs.existsSync(path.join(rootDir, 'app', 'vendor', 'autoload.php'));
const describeWithApp = hasApp ? describe : describe.skip;

/**
 * Every table of a database but the migration log (its timestamps differ per build), in a stable order.
 *
 * @param {string} dbPath
 * @returns {string}
 */
function dumpCatalog(dbPath) {
    return execFileSync('php', [
        '-r',
        '$db = new PDO("sqlite:".$argv[1]); '
        + '$tables = $db->query("SELECT name FROM sqlite_master WHERE type = \'table\' AND name NOT LIKE \'sqlite_%\' '
        + 'AND name != \'doctrine_migration_versions\' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN); '
        + 'foreach ($tables as $t) { '
        + 'echo $t, "\\n", json_encode($db->query("SELECT * FROM \\"$t\\" ORDER BY 1, 2")->fetchAll(PDO::FETCH_ASSOC)), "\\n"; }',
        dbPath,
    ], { encoding: 'utf8' });
}

function tree(dir) {
    return fs.readdirSync(dir, { recursive: true }).sort().join('\n');
}

describeWithApp('fixture', () => {
    afterAll(() => disposeFixture());

    it('has catalog entries, a label, a storage, covers and settings', () => {
        const env = createIsolatedEnv();
        try {
            const dump = dumpCatalog(path.join(env.dir, 'data.db'));
            expect(dump).toMatch(/Fullmetal Alchemist: Brotherhood/);
            expect(dump).toMatch(/Fixture storage/);
            expect(dump).toMatch(/Sample/);
            expect(fs.readdirSync(path.join(env.dir, 'media')).length).toBeGreaterThan(1);
            expect(JSON.parse(fs.readFileSync(path.join(env.dir, 'config.json'), 'utf8')).locale).toBe('en');
        } finally {
            env.cleanup();
        }
    });

    it('is reproducible: two builds give the same data', () => {
        const a = fs.mkdtempSync(path.join(require('os').tmpdir(), 'animedb-fixture-a-'));
        const b = fs.mkdtempSync(path.join(require('os').tmpdir(), 'animedb-fixture-b-'));
        try {
            buildFixture(a);
            buildFixture(b);
            expect(dumpCatalog(path.join(b, 'data.db'))).toBe(dumpCatalog(path.join(a, 'data.db')));
            expect(tree(b)).toBe(tree(a));
        } finally {
            fs.rmSync(a, { recursive: true, force: true });
            fs.rmSync(b, { recursive: true, force: true });
        }
    });

    it('keeps environments independent and points every path into the copy', () => {
        const first  = createIsolatedEnv();
        const second = createIsolatedEnv();
        try {
            for (const value of Object.values(first.env)) {
                expect(value).toContain(first.dir);
            }

            fs.writeFileSync(path.join(first.dir, 'data.db'), '');
            expect(dumpCatalog(path.join(second.dir, 'data.db'))).toMatch(/Fullmetal Alchemist/);
        } finally {
            first.cleanup();
            second.cleanup();
        }
    });

    it('refuses to load into a non-empty catalog', () => {
        const env = createIsolatedEnv();
        try {
            expect(() => execFileSync('php', [path.join(rootDir, 'app', 'bin', 'console'), 'app:fixture:load', '--no-interaction'], {
                cwd: path.join(rootDir, 'app'),
                env: { ...process.env, ...env.env },
                stdio: 'pipe',
            })).toThrow();
        } finally {
            env.cleanup();
        }
    });

    it('does not touch the developer data/ and app/var/config.json', () => {
        const paths = [
            path.join(rootDir, 'data', 'data.db'),
            path.join(rootDir, 'app', 'var', 'config.json'),
        ];
        const mtime = (p) => (fs.existsSync(p) ? fs.statSync(p).mtimeMs : null);
        const before = paths.map(mtime);

        const env = createIsolatedEnv();
        try {
            execFileSync('php', [path.join(rootDir, 'app', 'bin', 'console'), 'app:queue:purge', '--no-interaction'], {
                cwd: path.join(rootDir, 'app'),
                env: { ...process.env, ...env.env },
                stdio: 'pipe',
            });
        } finally {
            env.cleanup();
        }

        expect(paths.map(mtime)).toEqual(before);
    });
});
