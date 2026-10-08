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
 * The release E2E workflow (issue #942) is the one workflow nothing else can check: it fires on a
 * tag, so a mistake in it surfaces at the next release and nowhere earlier. These tests pin the
 * four properties the issue states as acceptance criteria — the triggers (tag and dispatch, never
 * a pull request), the failure artifacts, and the two commands the run is made of — plus the one
 * invariant that silently rots: the Electron library list, which must stay identical to the one
 * frontend-smoke.yml already carries.
 *
 * No YAML parser: the repository has no YAML dependency, and the lines these tests read are
 * unambiguous (see tests/scripts/build-workflow-extensions.test.js for the same reasoning).
 */

'use strict';

const fs   = require('fs');
const path = require('path');

const { assetUrl, ASSET_NAME } = require('../../scripts/download-e2e-runtime');

const repoRoot = path.join(__dirname, '..', '..');
const workflowsDir = path.join(repoRoot, '.github', 'workflows');

/**
 * @param {string} name
 * @returns {string}
 */
function workflow(name) {
    return fs.readFileSync(path.join(workflowsDir, name), 'utf8');
}

/**
 * Package names of the `apt-get install` line of a workflow, order-insensitive.
 *
 * @param {string} contents
 * @returns {string[]}
 */
function aptPackages(contents) {
    const block = contents.match(/apt-get install -y --no-install-recommends([\s\S]*?)\n\n/);
    if (block === null) {
        return [];
    }

    return block[1]
        .replace(/\\\n/g, ' ')
        .split(/\s+/)
        .filter((name) => name !== '')
        .sort();
}

describe('release E2E workflow triggers', () => {
    const contents = workflow('e2e.yml');

    test('runs on a version tag', () => {
        expect(contents).toMatch(/on:\n\s+push:\n\s+tags:\n\s+- 'v\*\.\*\.\*'/);
    });

    test('runs on a manual dispatch', () => {
        expect(contents).toMatch(/^\s*workflow_dispatch:\s*$/m);
    });

    /**
     * The decision of issue #942 and the reason this workflow exists separately: the set is heavy,
     * so a pull request keeps getting frontend-smoke.yml and the unit jobs instead. A `pull_request`
     * trigger appearing here would silently multiply the cost of every PR.
     */
    test('does not run on a pull request', () => {
        expect(contents).not.toMatch(/^\s*pull_request:/m);
    });

    /** A branch push would turn the release run into an every-commit run just as well. */
    test('does not run on a branch push', () => {
        expect(contents).not.toMatch(/^\s+branches:/m);
    });
});

describe('release E2E workflow steps', () => {
    const contents = workflow('e2e.yml');

    test('downloads the pinned Linux FrankenPHP and runs the set', () => {
        const scripts = JSON.parse(fs.readFileSync(path.join(repoRoot, 'package.json'), 'utf8')).scripts;

        for (const command of ['download-e2e-runtime', 'e2e']) {
            expect(contents).toContain(`run: npm run ${command}`);
            expect(scripts).toHaveProperty(command);
        }
    });

    /**
     * Without artifacts a red run in CI is one line of stdout, and a set nobody can debug is a set
     * people start running past (issue #942). `if: failure()` rather than `always()`: a green run
     * leaves traces of 29 passing scenarios nobody will ever open.
     */
    test('uploads the traces of a failed run', () => {
        expect(contents).toMatch(/if: failure\(\)\n\s+uses: actions\/upload-artifact@/);
        expect(contents).toMatch(/path: e2e-results\//);
    });

    test('has a timeout as a hang boundary', () => {
        expect(contents).toMatch(/timeout-minutes: \d+/);
    });

    /**
     * The same libraries Electron needs under Xvfb. Two copies of the list exist because the two
     * workflows are deliberately separate; a drift between them means one run gets fixed and the
     * other stays red on a cause already solved next door.
     */
    test('installs exactly the Electron libraries the shots run installs', () => {
        expect(aptPackages(contents)).toEqual(aptPackages(workflow('frontend-smoke.yml')));
        expect(aptPackages(contents)).toContain('xvfb');
    });
});

describe('download-e2e-runtime', () => {
    test('asks for the FrankenPHP version pinned in versions.json', () => {
        const versions = JSON.parse(fs.readFileSync(path.join(repoRoot, 'scripts', 'versions.json'), 'utf8'));

        expect(assetUrl()).toBe(
            `https://github.com/php/frankenphp/releases/download/v${versions.frankenphp}/${ASSET_NAME}`,
        );
        // A bump that forgets the Linux checksum leaves the download unverifiable, so the pin is
        // part of the version bump, not an optional extra.
        expect(versions.sha256.frankenphpLinux).toMatch(/^[0-9a-f]{64}$/);
    });
});
