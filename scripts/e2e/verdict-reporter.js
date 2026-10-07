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

/*
 * Prints the run verdict: which scenarios failed (or timed out) and where their artifacts are.
 */
class VerdictReporter {
    constructor() {
        this.failed = [];
        this.total  = 0;
    }

    onTestEnd(test, result) {
        this.total += 1;
        if (result.status === 'passed' || result.status === 'skipped') {
            return;
        }
        const artifacts = result.attachments
            .filter((a) => a.path && a.name === 'trace')
            .map((a) => a.path);
        this.failed.push({ title: test.titlePath().filter(Boolean).join(' › '), status: result.status, artifacts });
    }

    onEnd() {
        if (this.failed.length === 0) {
            console.log(`\n[e2e] verdict: PASSED (${this.total} scenarios)`);
            return;
        }
        console.error(`\n[e2e] verdict: FAILED (${this.failed.length} of ${this.total} scenarios)`);
        for (const f of this.failed) {
            console.error(`[e2e]   ${f.status === 'timedOut' ? 'TIMED OUT' : 'FAILED'}: ${f.title}`);
            for (const a of f.artifacts) {
                console.error(`[e2e]     trace: ${a}  (npx playwright show-trace <file>)`);
            }
        }
    }

    printsToStdio() {
        return false;
    }
}

module.exports = VerdictReporter;
