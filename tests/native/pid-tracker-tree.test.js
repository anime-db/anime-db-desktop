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

// Integration: a real grandchild must die together with the tracked process (issue #757).
jest.mock('../../native/paths', () => ({ getRuntimeDir: jest.fn(() => '/fake/var') }));

const { spawn } = require('child_process');
const { killTree, killTreeSync } = require('../../native/supervisor/pid-tracker');

const describePosix = process.platform === 'win32' ? describe.skip : describe;

function alive(pid) {
    try {
        process.kill(pid, 0);
        return true;
    } catch {
        return false;
    }
}

async function waitDead(pid) {
    for (let i = 0; i < 50 && alive(pid); i++) {
        await new Promise((r) => setTimeout(r, 50));
    }
}

/** Spawns a group leader (`detached`) that has a long-living grandchild. */
async function spawnTree() {
    const parent = spawn('sh', ['-c', 'sleep 60 & echo $!; wait'], {
        detached: true,
        stdio: ['ignore', 'pipe', 'ignore'],
    });
    const grandchild = await new Promise((resolve) => {
        parent.stdout.once('data', (d) => resolve(parseInt(String(d).trim(), 10)));
    });
    return { parent, grandchild };
}

describePosix('killTree (real processes)', () => {
    test('async: kills the grandchild along with the process', async () => {
        const { parent, grandchild } = await spawnTree();
        expect(alive(grandchild)).toBe(true);

        await killTree(parent.pid);
        await waitDead(grandchild);

        expect(alive(grandchild)).toBe(false);
    });

    test('sync: kills the grandchild along with the process', async () => {
        const { parent, grandchild } = await spawnTree();
        expect(alive(grandchild)).toBe(true);

        killTreeSync(parent.pid);
        await waitDead(grandchild);

        expect(alive(grandchild)).toBe(false);
    });
});
