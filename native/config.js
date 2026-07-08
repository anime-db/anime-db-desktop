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
 * Config is stored at <userData>/config.json.
 *
 * @returns {Record<string, unknown>}
 */
function readConfig() {
    const configPath = path.join(paths.getUserDataDir(), 'config.json');
    if (!fs.existsSync(configPath)) {
        return {};
    }
    return JSON.parse(fs.readFileSync(configPath, 'utf8'));
}

/**
 * @param {Record<string, unknown>} config
 */
function writeConfig(config) {
    const configPath = path.join(paths.getUserDataDir(), 'config.json');
    fs.mkdirSync(path.dirname(configPath), { recursive: true });
    fs.writeFileSync(configPath, JSON.stringify(config, null, 2));
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
 * Maps a BCP 47 OS locale (e.g. ru-RU, ru-BY, en-US) to one of the app's supported locales.
 * No language whitelist: any ru-* locale (or bare "ru") maps to "ru", everything else falls back
 * to "en".
 *
 * @param {string} osLocale
 * @returns {'ru' | 'en'}
 */
function mapOsLocaleToAppLocale(osLocale) {
    return /^ru(-|$)/i.test(osLocale) ? 'ru' : 'en';
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

module.exports = { getOrCreateAppSecret, getOrCreateLocale, getLocale, mapOsLocaleToAppLocale };
