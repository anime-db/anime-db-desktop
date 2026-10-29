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
 * The release must not be published with a known vulnerable dependency (issue #1172): job `audit`
 * runs `npm run security:audit`, `build` needs it and `publish` needs `build`. build.yml runs only
 * on a tag or a manual dispatch, so a dropped `needs: audit` would switch the gate off silently
 * until a release went out — the same blind spot as issue #535. No YAML parser: jobs are split on
 * their two-space-indented keys, like the neighbouring workflow tests.
 */

const fs = require('fs');
const path = require('path');

const WORKFLOW = path.join(__dirname, '..', '..', '.github', 'workflows', 'build.yml');

function jobs(contents) {
    const body = contents.split(/^jobs:\s*$/m)[1];
    const result = {};
    let name = null;
    for (const line of body.split('\n')) {
        const m = line.match(/^ {2}([A-Za-z0-9_-]+):\s*$/);
        if (m) {
            name = m[1];
            result[name] = [];
        } else if (name !== null) {
            result[name].push(line);
        }
    }
    return Object.fromEntries(Object.entries(result).map(([k, v]) => [k, v.join('\n')]));
}

function withoutComments(text) {
    return text.split('\n').filter((l) => !/^\s*#/.test(l)).join('\n');
}

describe('build.yml release dependency audit gate', () => {
    const all = jobs(fs.readFileSync(WORKFLOW, 'utf8'));
    const audit = withoutComments(all.audit ?? '');
    const build = withoutComments(all.build ?? '');
    const publish = withoutComments(all.publish ?? '');

    test('job audit runs npm run security:audit', () => {
        expect(all.audit).toBeDefined();
        expect(audit).toMatch(/run:\s*npm run security:audit\s*$/m);
    });

    test('the audit step cannot be ignored', () => {
        expect(audit).not.toMatch(/continue-on-error:\s*true/);
    });

    test('build needs audit and is not forced to run after a red audit', () => {
        expect(build).toMatch(/^ {4}needs:\s*(audit|\[[^\]]*\baudit\b[^\]]*\])\s*$/m);
        expect(build).not.toMatch(/^ {4}if:.*(always\(\)|!cancelled\(\)|failure\(\))/m);
    });

    test('publish needs build, so it never runs without a green audit', () => {
        expect(publish).toMatch(/^ {4}needs:\s*(build|\[[^\]]*\bbuild\b[^\]]*\])\s*$/m);
        expect(publish).not.toMatch(/^ {4}if:.*(always\(\)|!cancelled\(\)|failure\(\))/m);
    });
});
