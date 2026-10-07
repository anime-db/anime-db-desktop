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
 * Electron main-process entry for the screenshot pipeline (see scripts/shots/run.js). Run
 * standalone as `electron scripts/shots/capture.js`, never through native/index.js — the real
 * app entry starts FrankenPHP, Meilisearch and the rest of the supervisor, none of which this
 * needs: the target server is a plain `php -S` process the orchestrator already has running.
 */

const { app, BrowserWindow, nativeTheme } = require('electron');
const fs   = require('fs');
const path = require('path');

const { PageErrorTracker } = require('./page-errors');
const { PID_MARKER, FAILED_MARKER, formatLastPageLine, saveFailureArtifacts } = require('./lifecycle');

const PORT    = process.env.SHOTS_PORT;
const OUT_DIR = process.env.SHOTS_OUT_DIR;
const ANIME_ID = process.env.SHOTS_ANIME_ID;

if (!PORT || !OUT_DIR || !ANIME_ID) {
    console.error('SHOTS_PORT, SHOTS_OUT_DIR and SHOTS_ANIME_ID must be set — this script is meant to be launched by scripts/shots/run.js');
    app.exit(1);
}

const WINDOW_WIDTH  = 1280;
const WINDOW_HEIGHT = 900;

// The catalog grid (app/assets/js/anime-list.js) fills in after an async fetch that runs past
// did-finish-load, so capturePage() right after page load can catch it empty regardless of
// database contents. Poll for the same DOM state anime-list.js itself uses to decide "loaded":
// either cards in the grid or the "list is empty" message uncovered.
const RENDER_POLL_SCRIPT = `(() => {
    const grid = document.getElementById('anime-list-grid');
    if (!grid) {
        return true;
    }
    const empty = document.getElementById('anime-list-empty');
    return grid.children.length > 0 || (empty !== null && !empty.hidden);
})()`;
const RENDER_POLL_INTERVAL_MS = 100;
const RENDER_POLL_TIMEOUT_MS  = 5000;

/**
 * @param {import('electron').BrowserWindow} win
 * @returns {Promise<boolean>} false if the deadline was hit without the grid settling
 */
async function waitForRender(win) {
    const deadline = Date.now() + RENDER_POLL_TIMEOUT_MS;
    do {
        if (await win.webContents.executeJavaScript(RENDER_POLL_SCRIPT)) {
            return true;
        }
        await new Promise((resolve) => setTimeout(resolve, RENDER_POLL_INTERVAL_MS));
    } while (Date.now() < deadline);
    return false;
}

/**
 * Pages captured for every theme. `anime-card` and `anime-edit` use the first catalog entry of
 * the data fixture (ANIME_ID, see scripts/fixture).
 *
 * @returns {{ name: string, path: string }[]}
 */
function buildPages() {
    return [
        { name: 'anime-card',      path: `/anime/${ANIME_ID}` },
        { name: 'anime-edit',      path: `/anime/${ANIME_ID}/edit` },
        { name: 'catalog',         path: '/' },
        { name: 'anime-new',       path: '/anime/new' },
        { name: 'storage',         path: '/storage' },
        { name: 'storage-new',     path: '/storage/new' },
        { name: 'settings',        path: '/settings' },
        { name: 'settings-labels', path: '/settings/labels' },
        { name: 'settings-proxy',  path: '/settings/proxy' },
        { name: 'settings-sync-review', path: '/settings/sync-review' },
        { name: 'market',          path: '/settings/market' },
        { name: 'plugins',         path: '/settings/plugins' },
        { name: 'plugin-widgets',  path: '/settings/plugins/widgets' },
    ];
}

/**
 * @param {import('electron').BrowserWindow} win
 * @param {string} url
 * @returns {Promise<void>}
 */
function loadPage(win, url) {
    return new Promise((resolve, reject) => {
        const onFinish = () => { win.webContents.off('did-fail-load', onFail); resolve(); };
        const onFail = (_event, code, description) => {
            win.webContents.off('did-finish-load', onFinish);
            reject(new Error(`${url} failed to load: ${description} (${code})`));
        };
        win.webContents.once('did-finish-load', onFinish);
        win.webContents.once('did-fail-load', onFail);
        win.loadURL(url);
    });
}

// The page being processed, so a failure or a SIGTERM from run.js can snapshot it.
let current = null;
let failureSaved = false;

/**
 * @returns {Promise<void>}
 */
async function saveCurrentFailure() {
    if (current === null || failureSaved) return;
    failureSaved = true;
    // Fixed up front: the walk may move on while the snapshot is being taken.
    const { win, dir, name, label } = current;
    await saveFailureArtifacts(win.webContents, dir, name);
    console.log(`${FAILED_MARKER}${label}`);
}

// run.js sends SIGUSR2 to this process only on its own timeout and SIGKILLs the group after a
// grace period. SIGTERM to the whole group would kill Xvfb and the renderer first, and Chromium
// installs its own SIGTERM handler; SIGUSR2 is left alone by both.
process.on('SIGUSR2', () => {
    saveCurrentFailure().finally(() => app.exit(1));
});

async function main() {
    console.log(`${PID_MARKER}${process.pid}`);
    await app.whenReady();

    const pages   = buildPages();
    const tracker = new PageErrorTracker();

    // One window for the whole run, reused across themes and pages: recreating it between passes
    // was observed to make the first load of the next pass fail with ERR_FAILED.
    const win = new BrowserWindow({
        width:  WINDOW_WIDTH,
        height: WINDOW_HEIGHT,
        webPreferences: {
            // A hidden/backgrounded window can have its rendering throttled, which would produce
            // blank or stale captures under Xvfb — the window is shown instead.
            backgroundThrottling: false,
        },
    });

    // Attributes console/network failures to whichever page was loading when they fired; set
    // right before each loadURL() below.
    let currentPageUrl = null;

    win.webContents.on('console-message', (event) => {
        tracker.recordConsoleMessage(currentPageUrl, event);
    });

    // did-fail-load (see loadPage() below) only fires for the document navigation itself — a
    // missing <script src="...">, e.g. an unbuilt app/public/js/main.js, is a subresource fetch
    // and never triggers it. webRequest sees every request the page makes, so it is the only
    // reliable way to catch that case.
    const { webRequest } = win.webContents.session;
    webRequest.onCompleted((details) => {
        // did-finish-load fires whatever the HTTP status, so a 4xx/5xx document is judged here.
        tracker.recordMainFrameResponse(currentPageUrl, details);
        if (details.resourceType !== 'script' || details.statusCode < 400) return;
        tracker.recordFailedResource(currentPageUrl, details);
    });
    webRequest.onErrorOccurred((details) => {
        // ERR_ABORTED is routine when the page navigates away before an in-flight script request
        // finishes — not a page defect.
        if (details.resourceType !== 'script' || details.error === 'net::ERR_ABORTED') return;
        tracker.recordFailedResource(currentPageUrl, details);
    });

    for (const theme of ['light', 'dark']) {
        // app/assets/js/color-mode.js sets data-bs-theme from `prefers-color-scheme`, which this
        // drives directly — no in-page toggle exists yet to click instead.
        nativeTheme.themeSource = theme;

        const themeDir = path.join(OUT_DIR, theme);
        fs.mkdirSync(themeDir, { recursive: true });

        for (const targetPage of pages) {
            const url = `http://127.0.0.1:${PORT}${targetPage.path}`;
            currentPageUrl = url;
            current = { win, dir: themeDir, name: targetPage.name, label: `${theme}/${targetPage.name}` };
            console.log(formatLastPageLine(`${theme}/${targetPage.name} (${url})`));
            await loadPage(win, url);

            if (!(await waitForRender(win))) {
                tracker.recordFailure(url, 'catalog grid did not settle within the timeout');
            }

            const image = await win.webContents.capturePage();
            fs.writeFileSync(path.join(themeDir, `${targetPage.name}.png`), image.toPNG());
            console.log(`[shots] ${theme}/${targetPage.name}.png`);
        }
    }

    if (tracker.hasFailures()) {
        console.error(`[shots] page errors detected:\n${tracker.formatReport()}`);
        app.exit(1);
        return;
    }

    app.exit(0);
}

main().catch(async (err) => {
    console.error(`[shots] ${err.stack || err.message}`);
    await saveCurrentFailure();
    app.exit(1);
});
