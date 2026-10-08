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
 * The release build installs Composer dependencies with the runner's PHP, and `config.platform` is
 * not set in app/composer.json — so composer checks platform requirements against that PHP and
 * fails the whole build when an extension from `require` is not enabled. setup-php enables only
 * what it is told plus its own defaults, which differ per OS.
 *
 * This has drifted silently before: commit fa14290 (2026-08-23) added ext-pdo_sqlite and
 * ext-openssl to require without touching .github/workflows/build.yml, and the release build was
 * broken from that day. Nothing noticed, because that workflow runs only on a tag and none were
 * pushed; it surfaced on a manual dispatch five days later (issue #535).
 *
 * The check is a subset assertion, not equality: a workflow may legitimately enable more than
 * `require` names (runtime-parity.yml also wants apcu) — but never less.
 *
 * Covered are the workflows whose trigger hides drift: build.yml and runtime-parity.yml (Windows,
 * tag/dispatch only) and e2e.yml (ubuntu, tag/dispatch only — issue #942). The remaining ubuntu
 * jobs run `composer install` too, but they run on every pull request, so a missing extension
 * there is red the same day someone adds it to `require`. build.yml runs only on a tag, which is
 * exactly why its drift went unseen for five days.
 */

'use strict';

const fs   = require('fs');
const path = require('path');

const repoRoot = path.join(__dirname, '..', '..');

/**
 * Reads the `extensions:` value out of a workflow without a YAML parser: the repository has no
 * YAML dependency, and pulling one in for a single scalar line would cost more than it explains.
 * The line is unambiguous — one `extensions:` key per file, a comma-separated scalar.
 *
 * @param {string} workflowPath
 * @returns {string[]}
 */
function workflowExtensions(workflowPath) {
    const contents = fs.readFileSync(workflowPath, 'utf8');
    const matches = contents.match(/^\s*extensions:\s*(.+)$/m);

    if (matches === null) return [];

    return matches[1]
        .split(',')
        .map((name) => name.trim())
        .filter((name) => name !== '');
}

/** @returns {string[]} extension names (without the `ext-` prefix) from app/composer.json's require */
function composerRequiredExtensions() {
    const composer = JSON.parse(fs.readFileSync(path.join(repoRoot, 'app', 'composer.json'), 'utf8'));

    return Object.keys(composer.require)
        .filter((name) => name.startsWith('ext-'))
        .map((name) => name.slice('ext-'.length));
}

describe('build.yml PHP extensions', () => {
    test('enable every extension app/composer.json requires', () => {
        const enabled = workflowExtensions(path.join(repoRoot, '.github', 'workflows', 'build.yml'));
        const required = composerRequiredExtensions();

        expect(required.length).toBeGreaterThan(0);
        expect(enabled).toEqual(expect.arrayContaining(required));
    });

    /**
     * Same reasoning for the parity job: it runs `composer install` too, and since issue #467
     * composer also verifies platform requirements at runtime through platform_check.php, so a
     * missing extension there fails on the autoloader rather than on a readable step.
     */
    test('the runtime-parity workflow enables them too', () => {
        const enabled = workflowExtensions(path.join(repoRoot, '.github', 'workflows', 'runtime-parity.yml'));

        expect(enabled).toEqual(expect.arrayContaining(composerRequiredExtensions()));
    });

    /**
     * And the release E2E run (issue #942): it is an ubuntu job, but it runs only on a tag or a
     * manual dispatch, so it shares build.yml's blind spot rather than the pull-request jobs'
     * same-day feedback. setup-php on ubuntu happens to enable most of these by default, which is
     * precisely what would make the drift silent until a release.
     */
    test('the release E2E workflow enables them too', () => {
        const enabled = workflowExtensions(path.join(repoRoot, '.github', 'workflows', 'e2e.yml'));

        expect(enabled).toEqual(expect.arrayContaining(composerRequiredExtensions()));
    });
});

describe('workflowExtensions', () => {
    test('splits the comma-separated list and trims it', () => {
        const file = path.join(__dirname, '..', '..', '.github', 'workflows', 'build.yml');

        expect(workflowExtensions(file)).toContain('pdo_sqlite');
        expect(workflowExtensions(file).every((name) => name === name.trim())).toBe(true);
    });
});
