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

const PORT    = process.env.SHOTS_PORT;
const OUT_DIR = process.env.SHOTS_OUT_DIR;
// Empty string means the orchestrator found no anime row to link to — set by run.js.
const ANIME_ID = process.env.SHOTS_ANIME_ID || null;

if (!PORT || !OUT_DIR) {
    console.error('SHOTS_PORT and SHOTS_OUT_DIR must be set — this script is meant to be launched by scripts/shots/run.js');
    app.exit(1);
}

const WINDOW_WIDTH  = 1280;
const WINDOW_HEIGHT = 900;

/**
 * Pages captured for every theme. `anime-card` is included only when the orchestrator found an
 * existing anime row (ANIME_ID), since the catalog is empty on a fresh clone until demo data is
 * seeded manually (see README).
 *
 * @returns {{ name: string, path: string }[]}
 */
function buildPages() {
    const pages = [
        { name: 'catalog',    path: '/anime' },
        { name: 'anime-new',  path: '/anime/new' },
        { name: 'storage',    path: '/storage' },
        { name: 'settings',   path: '/settings' },
        { name: 'market',     path: '/settings/market' },
    ];

    if (ANIME_ID !== null) {
        pages.unshift({ name: 'anime-card', path: `/anime/${ANIME_ID}` });
    } else {
        console.warn('[shots] no anime found in the catalog — skipping anime-card (see README on seeding demo data)');
    }

    return pages;
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

async function main() {
    await app.whenReady();

    const pages = buildPages();

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

    for (const theme of ['light', 'dark']) {
        // app/public/js/color-mode.js sets data-bs-theme from `prefers-color-scheme`, which this
        // drives directly — no in-page toggle exists yet to click instead.
        nativeTheme.themeSource = theme;

        const themeDir = path.join(OUT_DIR, theme);
        fs.mkdirSync(themeDir, { recursive: true });

        for (const targetPage of pages) {
            const url = `http://127.0.0.1:${PORT}${targetPage.path}`;
            await loadPage(win, url);

            const image = await win.webContents.capturePage();
            fs.writeFileSync(path.join(themeDir, `${targetPage.name}.png`), image.toPNG());
            console.log(`[shots] ${theme}/${targetPage.name}.png`);
        }
    }

    app.exit(0);
}

main().catch((err) => {
    console.error(`[shots] ${err.stack || err.message}`);
    app.exit(1);
});
