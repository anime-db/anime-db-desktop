/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

'use strict';

const crypto = require('crypto');
const fs     = require('fs');
const path   = require('path');
const { app } = require('electron');
const paths  = require('./paths');

/**
 * Config is stored at <userData>/config.json. A missing, truncated or otherwise corrupt file
 * (e.g. a write from the PHP side was interrupted) degrades to an empty config instead of
 * throwing, symmetrically with AppSettingsProvider::readConfig() on the PHP side.
 *
 * @returns {Record<string, unknown>}
 */
function readConfig() {
    const configPath = path.join(paths.getUserDataDir(), 'config.json');
    if (!fs.existsSync(configPath)) {
        return {};
    }
    try {
        const parsed = JSON.parse(fs.readFileSync(configPath, 'utf8'));
        return (parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)) ? parsed : {};
    } catch {
        return {};
    }
}

/**
 * Writes to a temporary file in the same directory and renames it over the target path, so a
 * concurrent read from the PHP side never observes a partially written file (rename is atomic
 * within a filesystem).
 *
 * @param {Record<string, unknown>} config
 */
function writeConfig(config) {
    const configPath = path.join(paths.getUserDataDir(), 'config.json');
    const dir = path.dirname(configPath);
    fs.mkdirSync(dir, { recursive: true });

    const tmpPath = path.join(dir, `.config.json.${process.pid}.${Date.now()}.tmp`);
    fs.writeFileSync(tmpPath, JSON.stringify(config, null, 2));
    fs.renameSync(tmpPath, configPath);
}

/**
 * Returns the existing APP_SECRET or generates and persists a new one.
 *
 * @returns {string} 64-character hex secret
 */
function getOrCreateAppSecret() {
    const config = readConfig();

    if (!config.appSecret) {
        config.appSecret = crypto.randomBytes(32).toString('hex');
        writeConfig(config);
    }

    return config.appSecret;
}

/**
 * OS locale prefixes (BCP 47 primary language subtag) that map to the "ru" app locale.
 * Besides "ru" itself, this covers post-Soviet states where Russian is a widely understood
 * second language: Belarus (be), Kazakhstan (kk), Kyrgyzstan (ky), Tajikistan (tg),
 * Uzbekistan (uz), Armenia (hy), Azerbaijan (az).
 *
 * @type {string[]}
 */
const RU_PREFERRED_PREFIXES = ['ru', 'be', 'kk', 'ky', 'tg', 'uz', 'hy', 'az'];

/**
 * Maps a BCP 47 OS locale (e.g. ru-RU, ru-BY, en-US) to one of the app's supported locales.
 * Any locale whose primary language subtag is in RU_PREFERRED_PREFIXES maps to "ru", everything
 * else falls back to "en".
 *
 * @param {string} osLocale
 * @returns {'ru' | 'en'}
 */
function mapOsLocaleToAppLocale(osLocale) {
    const prefix = osLocale.split('-')[0].toLowerCase();
    return RU_PREFERRED_PREFIXES.includes(prefix) ? 'ru' : 'en';
}

/**
 * Returns the existing app locale or derives and persists one from app.getLocale() on first run.
 *
 * @returns {string}
 */
function getOrCreateLocale() {
    const config = readConfig();

    if (!config.locale) {
        config.locale = mapOsLocaleToAppLocale(app.getLocale());
        writeConfig(config);
    }

    return config.locale;
}

/**
 * Reads the current app locale straight from config.json, so a value changed elsewhere
 * (e.g. the settings screen) is picked up without an app restart.
 *
 * @returns {string}
 */
function getLocale() {
    const config = readConfig();
    return config.locale || mapOsLocaleToAppLocale(app.getLocale());
}

/**
 * Reads the "proxy" key from config.json (written by ProxyConfigProvider on the PHP side).
 * Returns null when the key is missing or malformed, treated by callers the same as
 * `{ mode: 'none' }` (no proxy configured).
 *
 * @returns {Record<string, unknown> | null}
 */
function getProxySettings() {
    const config = readConfig();
    return (config.proxy !== null && typeof config.proxy === 'object' && !Array.isArray(config.proxy))
        ? config.proxy
        : null;
}

module.exports = {
    getOrCreateAppSecret,
    getOrCreateLocale,
    getLocale,
    mapOsLocaleToAppLocale,
    getProxySettings,
};
