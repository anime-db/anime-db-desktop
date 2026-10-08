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
 * properties the issue states as acceptance criteria — the triggers (exactly a tag and a dispatch),
 * the failure artifacts, and the two commands the run is made of — plus the one invariant that
 * silently rots: the Electron library list, which must stay identical to the one
 * frontend-smoke.yml already carries. The checksum behaviour of the download itself lives in
 * tests/scripts/download-e2e-runtime.test.js.
 *
 * No YAML parser: the repository has no YAML dependency, and the lines these tests read are
 * unambiguous (see tests/scripts/build-workflow-extensions.test.js for the same reasoning).
 */

'use strict';

const fs   = require('fs');
const path = require('path');

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

/**
 * Why a whitelist of the whole `on:` block instead of asserting the absence of specific triggers:
 * the list of ways to widen a trigger is open-ended and the dangerous entries are the ones nobody
 * thinks to forbid. `pull_request_target` is the sharp example — it runs on pull requests from
 * forks *with* the repository's secrets and a write-scoped GITHUB_TOKEN. The inline form
 * (`on: [push, pull_request]`), a `push:` with no `tags:` filter and `schedule:` are three more.
 * Comparing the block as a whole makes any added key red, named or not.
 *
 * @returns {string} the `on:` block, comments and blank lines removed
 */
function triggerBlock(contents) {
    const lines = contents.split('\n');
    const start = lines.findIndex((line) => /^on:/.test(line));
    const rest = lines.slice(start + 1);
    const end = rest.findIndex((line) => /^[^\s#]/.test(line));

    return [lines[start], ...(end === -1 ? rest : rest.slice(0, end))]
        .filter((line) => line.trim() !== '' && !line.trim().startsWith('#'))
        .join('\n');
}

describe('release E2E workflow triggers', () => {
    test('are exactly a version tag and a manual dispatch', () => {
        expect(triggerBlock(workflow('e2e.yml'))).toBe(
            [
                'on:',
                '  push:',
                '    tags:',
                "      - 'v*.*.*'",
                '  workflow_dispatch:',
            ].join('\n'),
        );
    });

    test('triggerBlock stops at the next top-level key', () => {
        expect(triggerBlock('name: x\non:\n  push:\n    tags: [a]\n\njobs:\n  one:\n'))
            .toBe('on:\n  push:\n    tags: [a]');
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
