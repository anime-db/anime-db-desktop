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

const fs = require('fs');

const POLL_INTERVAL_MS = 15 * 1000;
const LIST_REFRESH_INTERVAL_MS = 5 * 60 * 1000;
const ACCESS_TIMEOUT_MS = 5 * 1000;
/** access() runs in the libuv pool (4 threads by default); keep spare threads for the rest of the main process. */
const MAX_CONCURRENT_CHECKS = 2;
/** Consecutive identical observations needed to accept a change of state. */
const CONFIRMATIONS = 2;

const STORAGE_CHANGED_EVENT = 'storage.changed';

const DRIVE_LETTERS = Array.from({ length: 26 }, (_, i) => String.fromCharCode(65 + i));

/**
 * Tracks one boolean ("is reachable") signal. The first observation is a baseline and never
 * reports a transition; a change of state is accepted only after CONFIRMATIONS identical
 * observations in a row, so a single blip between two equal observations is ignored.
 */
class Tracker {
    constructor() {
        this.stable = null;
        this.streak = 0;
    }

    /**
     * @param {boolean} value
     * @returns {boolean} true when this observation completes an "unavailable → available" transition
     */
    observe(value) {
        if (this.stable === null) {
            this.stable = value;
            return false;
        }
        if (value === this.stable) {
            this.streak = 0;
            return false;
        }
        this.streak += 1;
        if (this.streak < CONFIRMATIONS) return false;
        this.stable = value;
        this.streak = 0;
        return value === true;
    }
}

/**
 * Polls the roots of known storages and reports the ones that became reachable. Holds no
 * business logic: ids and paths come from the backend list, the only action is `connected(id)`.
 *
 * @param {object} deps
 * @param {() => Promise<Array<{id: (string|number), path: string}>>} deps.listStorages
 * @param {(id: string) => Promise<void>} deps.connected
 * @param {(path: string) => Promise<void>} [deps.access]
 * @param {string} [deps.platform]
 * @param {number} [deps.timeoutMs]
 * @param {number} [deps.maxConcurrent]
 * @param {(...args: any[]) => void} [deps.log]
 */
function createStorageWatcher({
    listStorages,
    connected,
    access = (p) => fs.promises.access(p),
    platform = process.platform,
    timeoutMs = ACCESS_TIMEOUT_MS,
    maxConcurrent = MAX_CONCURRENT_CHECKS,
    log = (...args) => console.error('[storage-watcher]', ...args),
}) {
    /** @type {Map<string, {path: string, tracker: Tracker}>} */
    let storages = new Map();
    /** @type {Map<string, Tracker>} */
    let letters = new Map();
    /**
     * Checks whose access() has not settled yet; cleared only when the access promise settles (a timeout
     * does not free the entry), so its size is the number of operations occupying libuv threads.
     */
    const inFlight = new Set();
    let generation = 0;
    /** Index of the first candidate skipped by the concurrency limit in the last poll. */
    let cursor = 0;
    let running = false;
    let paused = false;
    let pollTimer = null;
    let listTimer = null;
    let refreshing = null;
    let signalQueue = Promise.resolve();

    /**
     * @returns {Promise<boolean>|null} null when the previous check of this root is still pending
     *   or the limit of concurrent checks is reached; it will be retried on a later poll
     */
    function check(key, path) {
        if (inFlight.has(key) || inFlight.size >= maxConcurrent) return null;
        inFlight.add(key);
        const probe = Promise.resolve()
            .then(() => access(path))
            .then(() => true, () => false)
            .finally(() => inFlight.delete(key));
        let timer;
        const timeout = new Promise((resolve) => { timer = setTimeout(() => resolve(false), timeoutMs); });
        return Promise.race([probe, timeout]).finally(() => clearTimeout(timer));
    }

    function enqueue(id) {
        signalQueue = signalQueue.then(async () => {
            if (!running || paused) return;
            try {
                await connected(id);
            } catch (err) {
                log(`app:storage:connected ${id} failed:`, err.message);
            }
        });
    }

    async function refresh() {
        if (!running) return;
        if (refreshing) return refreshing;
        refreshing = (async () => {
            const gen = generation;
            let list;
            try {
                list = await listStorages();
            } catch (err) {
                log('storage list failed:', err.message);
                return;
            }
            if (gen !== generation || !Array.isArray(list)) return;
            const next = new Map();
            for (const item of list) {
                if (!item || item.id === undefined || item.id === null || typeof item.path !== 'string') continue;
                const id = String(item.id);
                const prev = storages.get(id);
                next.set(id, prev && prev.path === item.path ? prev : { path: item.path, tracker: new Tracker() });
            }
            storages = next;
        })().finally(() => { refreshing = null; });
        return refreshing;
    }

    async function poll() {
        if (!running || paused) return;
        const gen = generation;
        const connectedNow = [];
        let newVolume = false;

        // Roots of known storages first, then drive letters.
        const candidates = [];
        for (const [id, entry] of storages) {
            candidates.push({
                key: `storage:${id}`,
                path: entry.path,
                onResult: (ok) => { if (entry.tracker.observe(ok)) connectedNow.push(id); },
            });
        }
        if (platform === 'win32') {
            for (const letter of DRIVE_LETTERS) {
                candidates.push({
                    key: `letter:${letter}`,
                    path: `${letter}:\\`,
                    onResult: (ok) => {
                        if (!letters.has(letter)) letters.set(letter, new Tracker());
                        if (letters.get(letter).observe(ok)) newVolume = true;
                    },
                });
            }
        }

        // `maxConcurrent` lanes take candidates in turn, starting where the previous poll was cut off by
        // the limit. A candidate that cannot start (its key or the limit is busy) is not observed and is
        // left for a later poll.
        const total = candidates.length;
        let next = total > 0 ? cursor % total : 0;
        let taken = 0;
        let firstLimited = null;
        const lane = async () => {
            while (taken < total) {
                const index = next;
                next = (next + 1) % total;
                taken += 1;
                const candidate = candidates[index];
                const limited = !inFlight.has(candidate.key) && inFlight.size >= maxConcurrent;
                const result = check(candidate.key, candidate.path);
                if (!result) {
                    if (limited && firstLimited === null) firstLimited = index;
                    continue;
                }
                const ok = await result;
                if (gen === generation) candidate.onResult(ok);
            }
        };
        await Promise.all(Array.from({ length: Math.max(1, maxConcurrent) }, lane));
        if (firstLimited !== null) cursor = firstLimited;
        else cursor = 0;
        if (gen !== generation || !running || paused) return;

        const ids = new Set(connectedNow);
        if (newVolume) {
            for (const [id, entry] of storages) {
                if (entry.tracker.stable === false) ids.add(id);
            }
        }
        for (const id of ids) enqueue(id);
    }

    function resetState() {
        generation += 1;
        cursor = 0;
        for (const entry of storages.values()) entry.tracker = new Tracker();
        letters = new Map();
    }

    return {
        async start() {
            if (running) return;
            running = true;
            await refresh();
            pollTimer = setInterval(() => { poll().catch((err) => log('poll failed:', err.message)); }, POLL_INTERVAL_MS);
            listTimer = setInterval(() => { refresh().catch((err) => log('refresh failed:', err.message)); }, LIST_REFRESH_INTERVAL_MS);
            poll().catch((err) => log('poll failed:', err.message));
        },
        stop() {
            running = false;
            generation += 1;
            clearInterval(pollTimer);
            clearInterval(listTimer);
            pollTimer = null;
            listTimer = null;
        },
        refresh,
        poll,
        /** Suspends signalling while the database is being replaced. */
        pause() {
            paused = true;
            resetState();
        },
        /** Resumes polling; the baseline is rebuilt from the next poll. */
        resume() {
            if (!paused) return;
            paused = false;
            resetState();
        },
        /** Resolves when all signals queued so far have been delivered. */
        idle: () => signalQueue,
    };
}

/**
 * Real wiring: the storage list and the signal are backend console commands run through
 * php-command.js. Required lazily so the factory above stays testable without the supervisor.
 */
function createDefaultWatcher() {
    const phpCommand = require('../supervisor/php-command');
    const supervisor = require('../supervisor');
    const LIST_TIMEOUT_MS = 30 * 1000;
    const CONNECTED_TIMEOUT_MS = 60 * 1000;

    return createStorageWatcher({
        async listStorages() {
            const context = supervisor.getWorkerContext();
            if (!context) throw new Error('backend is not running');
            const { code, stdout, stderr } = await phpCommand.run(
                'app:storage:list', ['--json'], context, LIST_TIMEOUT_MS, { rejectOnNonZero: false },
            );
            if (code !== 0) throw new Error(`exit code ${code}: ${stderr}`);
            return JSON.parse(stdout);
        },
        async connected(id) {
            const context = supervisor.getWorkerContext();
            if (!context) return;
            await phpCommand.run('app:storage:connected', [id], context, CONNECTED_TIMEOUT_MS);
        },
    });
}

let instance = null;

/** @returns {ReturnType<typeof createStorageWatcher>} */
function getWatcher() {
    if (!instance) instance = createDefaultWatcher();
    return instance;
}

module.exports = {
    createStorageWatcher,
    getWatcher,
    STORAGE_CHANGED_EVENT,
    POLL_INTERVAL_MS,
    LIST_REFRESH_INTERVAL_MS,
    ACCESS_TIMEOUT_MS,
    MAX_CONCURRENT_CHECKS,
};
