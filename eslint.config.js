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

const js      = require('@eslint/js');
const globals = require('globals');

module.exports = [
    // Сторонние скрипты, которые scripts/build-assets.js кладёт рядом с нашими: минифицированный
    // чужой код правилам проекта не подчиняется и правится не нами. В git этих файлов нет, поэтому
    // на чистом клоне линтер их и не видел — но стоит собрать ассеты локально, и `npm run lint`
    // падал бы на них.
    {
        ignores: ['app/public/js/*.min.js'],
    },
    js.configs.recommended,
    {
        languageOptions: {
            sourceType: 'commonjs',
            globals:    globals.node,
        },
    },
    {
        files:           ['app/public/js/**/*.js'],
        languageOptions: {
            sourceType: 'script',
            globals:    globals.browser,
        },
    },
    {
        files:           ['tests/**/*.js'],
        languageOptions: {
            globals: globals.jest,
        },
    },
    {
        files:           ['tests/public/**/*.js'],
        languageOptions: {
            globals: { ...globals.jest, ...globals.browser },
        },
    },
];
