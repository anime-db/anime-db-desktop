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

const semver = require('semver');
const { Provider, CancellationToken } = require('electron-updater');
const { parseUpdateInfo, resolveFiles } = require('electron-updater/out/providers/Provider');
const { parseKeys, verifySignature } = require('./signature');
const keysList = require('./update-keys.json');

/** Таймаут запроса одного файла одного источника, мс. */
const SOURCE_TIMEOUT_MS = 20000;

const CHANNEL_FILE = 'latest.yml';

/**
 * Жёсткий список источников; при равных версиях побеждает более ранний (GitHub).
 *
 * `feedUrl`  — каталог, где лежат latest.yml и latest.yml.sig;
 * `filesUrl` — функция: версия → каталог с файлами релиза.
 */
const SOURCES = Object.freeze([
    Object.freeze({
        name:     'github',
        feedUrl:  'https://github.com/anime-db/anime-db-desktop/releases/latest/download/',
        filesUrl: (version) => `https://github.com/anime-db/anime-db-desktop/releases/download/v${version}/`,
    }),
    Object.freeze({
        name:     'mr01',
        feedUrl:  'https://mr01.anime-db.org/app/',
        filesUrl: (version) => `https://mr01.anime-db.org/app/${version}/`,
    }),
]);

/**
 * Провайдер electron-updater: опрашивает все источники, принимает только latest.yml с верной
 * подписью (проверка по сырым байтам до разбора YAML), берёт наибольшую версию, при равенстве —
 * первый источник списка. Без подписи обновлений нет.
 */
class SignedUpdateProvider extends Provider {
    /**
     * @param {object} configuration
     * @param {object} updater
     * @param {object} runtimeOptions
     * @param {object} [overrides]  для тестов: sources, keys, timeoutMs
     */
    constructor(configuration, updater, runtimeOptions, overrides = {}) {
        super(runtimeOptions);
        this.updater = updater;
        this.sources = overrides.sources || SOURCES;
        this.keys = overrides.keys || parseKeys(keysList);
        this.timeoutMs = overrides.timeoutMs || SOURCE_TIMEOUT_MS;
        // updateInfo -> источник. Не поле провайдера: проверки из трея и по таймеру делят экземпляр.
        this.selected = new WeakMap();
    }

    get logger() {
        return this.updater.logger || console;
    }

    async fetchBytes(url) {
        const token = new CancellationToken();
        const timer = setTimeout(() => token.cancel(), this.timeoutMs);
        try {
            return await this.executor.downloadToBuffer(url, {
                headers:           this.requestHeaders || undefined,
                cancellationToken: token,
            });
        } finally {
            clearTimeout(timer);
        }
    }

    noCache(base, file) {
        const url = new URL(file, base);
        url.searchParams.set('noCache', Date.now().toString(32));
        return url;
    }

    /** @returns {Promise<{source: object, info: object}>} */
    async fetchFromSource(source) {
        const [data, sig] = await Promise.all([
            this.fetchBytes(this.noCache(source.feedUrl, CHANNEL_FILE)),
            this.fetchBytes(this.noCache(source.feedUrl, `${CHANNEL_FILE}.sig`)),
        ]);
        if (!verifySignature(data, sig, this.keys)) {
            throw new Error('signature verification failed');
        }
        const info = parseUpdateInfo(data.toString('utf8'), CHANNEL_FILE, source.feedUrl);
        if (!info || typeof info.version !== 'string' || !semver.valid(info.version)) {
            throw new Error('signed update info has no valid version');
        }
        return { source, info };
    }

    async getLatestVersion() {
        const results = await Promise.allSettled(this.sources.map(s => this.fetchFromSource(s)));
        let best = null;
        results.forEach((r, i) => {
            if (r.status === 'rejected') {
                const reason = r.reason && r.reason.message ? r.reason.message : String(r.reason);
                this.logger.warn(`Update source ${this.sources[i].name} rejected: ${reason}`);
            } else if (best === null || semver.gt(r.value.info.version, best.info.version)) {
                best = r.value;
            }
        });
        if (best === null) {
            const error = new Error('No update source returned verified update info');
            error.code = 'ERR_UPDATER_NO_VERIFIED_SOURCE';
            throw error;
        }
        this.selected.set(best.info, best.source);
        return best.info;
    }

    resolveFiles(updateInfo) {
        const source = this.selected.get(updateInfo);
        if (!source) {
            throw new Error('Update info was not obtained from a verified source');
        }
        return resolveFiles(updateInfo, new URL(source.filesUrl(updateInfo.version)));
    }
}

module.exports = { SignedUpdateProvider, SOURCES, SOURCE_TIMEOUT_MS };
