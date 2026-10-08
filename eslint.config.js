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
    js.configs.recommended,
    {
        languageOptions: {
            sourceType: 'commonjs',
            globals:    globals.node,
        },
    },
    {
        files:           ['app/assets/js/**/*.js'],
        languageOptions: {
            sourceType: 'script',
            globals:    globals.browser,
        },
    },
    {
        // E2E interaction goes through Playwright locators (trusted input, real hit-testing). A
        // scripted click inside evaluate()/executeJavaScript() reaches covered elements with
        // isTrusted=false and keeps a broken scenario green — see scripts/e2e/launch.js.
        files: ['scripts/e2e/**/*.js'],
        rules: {
            'no-restricted-syntax': ['error',
                {
                    selector: 'CallExpression[callee.property.name=dispatchEvent]',
                    message:  'No scripted interaction in E2E: dispatchEvent() is untrusted input; use Playwright locator actions.',
                },
                {
                    selector: 'CallExpression[callee.property.name=/^(evaluate|evaluateAll|evaluateHandle|executeJavaScript|addInitScript|\\$eval|\\$\\$eval|evalOnSelector|evalOnSelectorAll)$/] CallExpression[callee.property.name=/^(click|dblclick|dispatchEvent|focus|submit|requestSubmit)$/]',
                    message:  'No scripted interaction in E2E: use Playwright locator actions (locator.click() etc.).',
                },
                {
                    selector: 'CallExpression[callee.property.name=/^(click|dblclick|check|uncheck|setChecked|tap|hover|fill|selectOption|dragTo|dragAndDrop|press|type|clear|setInputFiles)$/] > ObjectExpression > Property[key.name=force][value.value=true]',
                    message:  'No scripted interaction in E2E: `force: true` skips the actionability checks (a covered element gets clicked anyway); wait for the element to become actionable instead.',
                },
                {
                    selector: 'CallExpression[callee.property.name=/^(evaluate|evaluateAll|evaluateHandle|executeJavaScript|addInitScript|\\$eval|\\$\\$eval|evalOnSelector|evalOnSelectorAll)$/] :matches(Literal[value=/\\.(click|dispatchEvent)\\(/], TemplateElement[value.raw=/\\.(click|dispatchEvent)\\(/])',
                    message:  'No scripted interaction in E2E: use Playwright locator actions (locator.click() etc.).',
                },
            ],
        },
    },
    {
        // page.evaluate() callbacks of scenarios run in the page, not in Node.
        files:           ['scripts/e2e/scenarios/**/*.js'],
        languageOptions: { globals: { ...globals.node, ...globals.browser } },
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
