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

// Only Node built-in modules may be required here: the script runs without `npm ci`.
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const rootDir = path.resolve(__dirname, '..');
const baselinePath = path.join(rootDir, 'security-audit-baseline.json');
const lockPath = path.join(rootDir, 'package-lock.json');

const EXIT_OK = 0;
const EXIT_FINDINGS = 1;
const EXIT_CONFIG = 2;
const EXIT_AUDIT_FAILED = 3;

const REQUIRED_SHIPPED_DEV = ['electron', 'htmx.org', 'bootstrap'];
const BLOCKING_SEVERITIES = new Set(['high', 'critical']);
const RAW_EXCERPT_LENGTH = 500;
const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

/** Raised when an audit did not run or its output is not the expected report. */
class AuditFailure extends Error {
    constructor(audit, message, raw) {
        super(message);
        this.audit = audit;
        this.raw = typeof raw === 'string' ? raw : '';
    }
}

/** Raised when the baseline file is unusable. */
class BaselineError extends Error {}

function parseJson(audit, raw) {
    let data;
    try {
        data = JSON.parse(raw);
    } catch {
        throw new AuditFailure(audit, 'output is not valid JSON', raw);
    }
    if (data === null || typeof data !== 'object' || Array.isArray(data)) {
        throw new AuditFailure(audit, 'output is not a JSON object', raw);
    }
    return data;
}

function advisoryId(entry) {
    if (typeof entry.url === 'string' && entry.url !== '') {
        return entry.url.split('/').filter(Boolean).pop();
    }
    return typeof entry.title === 'string' ? entry.title : 'unknown';
}

/**
 * Turns `npm audit --json` output into findings of severity >= high.
 * Only advisory objects in `via` are findings; string entries are propagation from other packages.
 */
function parseNpmReport(raw) {
    const data = parseJson('npm', raw);
    if (data.vulnerabilities === null || typeof data.vulnerabilities !== 'object' || Array.isArray(data.vulnerabilities)) {
        throw new AuditFailure('npm', 'report has no "vulnerabilities" key', raw);
    }
    const findings = [];
    for (const [pkg, vuln] of Object.entries(data.vulnerabilities)) {
        const nodes = Array.isArray(vuln && vuln.nodes) ? vuln.nodes : [];
        for (const via of (vuln && Array.isArray(vuln.via)) ? vuln.via : []) {
            if (via === null || typeof via !== 'object' || !BLOCKING_SEVERITIES.has(via.severity)) {
                continue;
            }
            findings.push({
                source:   'npm',
                id:       advisoryId(via),
                package:  pkg,
                severity: via.severity,
                title:    via.title || '',
                nodes,
            });
        }
    }
    return findings;
}

/**
 * Turns `composer audit --format=json` output into findings.
 * An advisory without a severity is kept: an unknown severity must not be silently dropped.
 */
function parseComposerReport(raw) {
    const data = parseJson('composer', raw);
    const advisories = data.advisories;
    if (advisories === null || typeof advisories !== 'object') {
        throw new AuditFailure('composer', 'report has no "advisories" key', raw);
    }
    const findings = [];
    const groups = Array.isArray(advisories) ? [advisories] : Object.values(advisories);
    for (const group of groups) {
        for (const adv of Array.isArray(group) ? group : Object.values(group || {})) {
            const severity = typeof adv.severity === 'string' ? adv.severity.toLowerCase() : 'unknown';
            if (severity !== 'unknown' && !BLOCKING_SEVERITIES.has(severity)) {
                continue;
            }
            findings.push({
                source:   'composer',
                id:       adv.cve || adv.advisoryId || 'unknown',
                package:  adv.packageName || 'unknown',
                severity,
                title:    adv.title || '',
                nodes:    [],
            });
        }
    }
    return findings;
}

/** Validates the baseline structure. Throws BaselineError on any problem. */
function validateBaseline(baseline) {
    if (baseline === null || typeof baseline !== 'object' || Array.isArray(baseline)) {
        throw new BaselineError('baseline must be a JSON object');
    }
    if (!Array.isArray(baseline.accepted)) {
        throw new BaselineError('baseline.accepted must be an array');
    }
    if (!Array.isArray(baseline.shipped_dev)) {
        throw new BaselineError('baseline.shipped_dev must be an array');
    }
    baseline.accepted.forEach((entry, i) => {
        if (entry === null || typeof entry !== 'object') {
            throw new BaselineError(`baseline.accepted[${i}] must be an object`);
        }
        for (const field of ['id', 'package', 'reason', 'expires']) {
            if (typeof entry[field] !== 'string' || entry[field].trim() === '') {
                throw new BaselineError(`baseline.accepted[${i}].${field} must be a non-empty string`);
            }
        }
        if (!ISO_DATE.test(entry.expires) || Number.isNaN(Date.parse(entry.expires))) {
            throw new BaselineError(`baseline.accepted[${i}].expires must be an ISO date (YYYY-MM-DD)`);
        }
    });
    for (const pkg of baseline.shipped_dev) {
        if (typeof pkg !== 'string' || pkg === '') {
            throw new BaselineError('baseline.shipped_dev must contain package names');
        }
    }
    for (const required of REQUIRED_SHIPPED_DEV) {
        if (!baseline.shipped_dev.includes(required)) {
            throw new BaselineError(`baseline.shipped_dev must contain "${required}"`);
        }
    }
}

/** Whether the finding ships to the user (true) or is build tooling only (false). */
function isShipped(finding, lockPackages, shippedDev) {
    if (finding.source !== 'npm') {
        return true;
    }
    if (shippedDev.includes(finding.package)) {
        return true;
    }
    if (finding.nodes.length === 0) {
        return true;
    }
    return finding.nodes.some((node) => {
        const entry = lockPackages[node];
        return !entry || entry.dev !== true;
    });
}

/**
 * Splits findings into blocking, accepted and build-chain groups.
 * `today` is an ISO date (YYYY-MM-DD, UTC); an entry is expired once today is later than `expires`.
 */
function classify(findings, baseline, lockPackages, today) {
    const result = {blocking: [], accepted: [], buildChain: []};
    for (const finding of findings) {
        if (!isShipped(finding, lockPackages, baseline.shipped_dev)) {
            result.buildChain.push(finding);
            continue;
        }
        const entry = baseline.accepted.find((a) => a.id === finding.id && a.package === finding.package);
        if (!entry) {
            result.blocking.push({...finding, why: 'not in baseline'});
        } else if (today > entry.expires) {
            result.blocking.push({...finding, why: `baseline entry expired on ${entry.expires}`});
        } else {
            result.accepted.push({...finding, expires: entry.expires});
        }
    }
    return result;
}

function formatFinding(f) {
    const extra = f.why ? ` — ${f.why}` : (f.expires ? ` — accepted until ${f.expires}` : '');
    return `  [${f.source}] ${f.package} ${f.id} (${f.severity})${f.title ? ` ${f.title}` : ''}${extra}`;
}

function runCommand(audit, command, args, cwd) {
    const res = spawnSync(command, args, {
        cwd,
        encoding:    'utf8',
        maxBuffer:   256 * 1024 * 1024,
       shell:       process.platform === 'win32',
    });
    if (res.error) {
        throw new AuditFailure(audit, `failed to run ${command}: ${res.error.message}`, '');
    }
    // The exit code is deliberately ignored: npm exits 1 on findings and composer's code is a bitmask.
    return res.stdout || '';
}

const defaultRunners = {
    npm:      () => runCommand('npm', 'npm', ['audit', '--json'], rootDir),
    composer: () => runCommand('composer', 'composer', ['audit', '--locked', '--no-dev', '--abandoned=ignore', '--format=json'], path.join(rootDir, 'app')),
};

function loadBaseline(readFile) {
    let baseline;
    try {
        baseline = JSON.parse(readFile(baselinePath));
    } catch (e) {
        throw new BaselineError(`cannot read baseline: ${e.message}`);
    }
    validateBaseline(baseline);
    return baseline;
}

function loadLockPackages(readFile) {
    try {
        const lock = JSON.parse(readFile(lockPath));
        return lock.packages || {};
    } catch (e) {
        throw new AuditFailure('npm', `cannot read package-lock.json: ${e.message}`, '');
    }
}

/**
 * Runs the whole check and returns the exit code. Everything environmental is injectable for tests.
 */
function run(options = {}) {
    const runners = {...defaultRunners, ...(options.runners || {})};
    const readFile = options.readFile || ((p) => fs.readFileSync(p, 'utf8'));
    const log = options.log || ((line) => console.log(line));
    const today = options.today || new Date().toISOString().slice(0, 10);

    const codes = [];
    const findings = [];
    let baseline = null;

    try {
        baseline = loadBaseline(readFile);
    } catch (e) {
        log(`Baseline error: ${e.message}`);
        codes.push(EXIT_CONFIG);
    }

    let lockPackages = {};
    const audits = [
        ['npm', parseNpmReport],
        ['composer', parseComposerReport],
    ];
    for (const [audit, parse] of audits) {
        try {
            findings.push(...parse(runners[audit]()));
            if (audit === 'npm') {
                lockPackages = loadLockPackages(readFile);
            }
        } catch (e) {
            if (!(e instanceof AuditFailure)) {
                throw e;
            }
            log(`Audit failed: ${e.audit}: ${e.message}`);
            if (e.raw !== '') {
                log(`  Raw output (first ${RAW_EXCERPT_LENGTH} chars): ${e.raw.slice(0, RAW_EXCERPT_LENGTH)}`);
            }
            codes.push(EXIT_AUDIT_FAILED);
        }
    }

    if (baseline) {
        const result = classify(findings, baseline, lockPackages, today);
        if (result.accepted.length > 0) {
            log('Accepted risks (baseline):');
            result.accepted.forEach((f) => log(formatFinding(f)));
        }
        if (result.buildChain.length > 0) {
            log('Build chain (not shipped, does not affect the exit code):');
            result.buildChain.forEach((f) => log(formatFinding(f)));
        }
        if (result.blocking.length > 0) {
            log('Blocking findings:');
            result.blocking.forEach((f) => log(formatFinding(f)));
            codes.push(EXIT_FINDINGS);
        }
    }

    const code = Math.max(EXIT_OK, ...codes);
    log(code === EXIT_OK ? 'Security audit passed.' : `Security audit failed with exit code ${code}.`);
    return code;
}

module.exports = {
    AuditFailure,
    BaselineError,
    parseNpmReport,
    parseComposerReport,
    validateBaseline,
    isShipped,
    classify,
    run,
    EXIT_OK,
    EXIT_FINDINGS,
    EXIT_CONFIG,
    EXIT_AUDIT_FAILED,
};

if (require.main === module) {
    process.exitCode = run();
}
