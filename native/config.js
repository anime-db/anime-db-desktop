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
const paths  = require('./paths');

/**
 * Returns the existing APP_SECRET or generates and persists a new one.
 * Config is stored at <userData>/config.json.
 *
 * @returns {string} 64-character hex secret
 */
function getOrCreateAppSecret() {
    const configPath = path.join(paths.getUserDataDir(), 'config.json');

    fs.mkdirSync(path.dirname(configPath), { recursive: true });

    let config = {};
    if (fs.existsSync(configPath)) {
        config = JSON.parse(fs.readFileSync(configPath, 'utf8'));
    }

    if (!config.appSecret) {
        config.appSecret = crypto.randomBytes(32).toString('hex');
        fs.writeFileSync(configPath, JSON.stringify(config, null, 2));
    }

    return config.appSecret;
}

module.exports = { getOrCreateAppSecret };
