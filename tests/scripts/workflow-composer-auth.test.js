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

/**
 * Токен composer нужен ровно на установку зависимостей. Если оставить его в глобальном
 * `~/.config/composer/auth.json`, он лежит там до конца джобы — то есть и на шагах, которые
 * исполняют код: lifecycle-скрипты зависимостей в `npm ci`, тесты, прогон приложения под Xvfb,
 * запуск собранного приложения на гейте релиза (issue #971). Поэтому после установки он снимается.
 *
 * Проверяются три вещи, и каждая ловит отдельную ошибку:
 *
 * 1. у каждого workflow, который ставит зависимости, есть и шаг с токеном, и шаг, снимающий его;
 * 2. снятие идёт ПОСЛЕ установки (иначе оно бессмысленно);
 * 3. ни один шаг `ramsey/composer-install` не несёт `COMPOSER_AUTH` в своём `env` — это выглядит
 *    как передача токена, но до composer не доходит (composite-action), и установка тихо идёт
 *    анонимно. Отказ при этом отложенный: при тёплом кэше composer сеть не нужна вовсе, поэтому
 *    ubuntu-джобы остаются зелёными, а падает только та, у которой кэш холодный. Подробности —
 *    `.claude-docs/gotchas.md`.
 *
 * Без YAML-парсера: в репозитории нет YAML-зависимости, а строки, которые читают эти тесты,
 * однозначны (то же обоснование, что в tests/scripts/build-workflow-extensions.test.js).
 */

'use strict';

const fs   = require('fs');
const path = require('path');

const workflowsDir = path.join(__dirname, '..', '..', '.github', 'workflows');

/** @returns {string[]} имена файлов всех workflow */
function workflowNames() {
    return fs.readdirSync(workflowsDir).filter((name) => name.endsWith('.yml')).sort();
}

/**
 * @param {string} name
 * @returns {string}
 */
function read(name) {
    return fs.readFileSync(path.join(workflowsDir, name), 'utf8');
}

/**
 * Номера строк трёх интересных шагов; -1, если шага нет.
 *
 * @param {string} contents
 * @returns {{ install: number, auth: number, unset: number }}
 */
function stepLines(contents) {
    const lines = contents.split('\n');
    const find = (predicate) => lines.findIndex(predicate);

    return {
        install: find((line) => line.includes('uses: ramsey/composer-install')),
        auth: find((line) => /^\s*run: composer config --global github-oauth\.github\.com /.test(line)),
        unset: find((line) => /^\s*run: composer config --global --unset github-oauth\.github\.com\s*$/.test(line)),
    };
}

/**
 * Текст шага установки — от строки с `uses:` до следующего шага того же уровня.
 *
 * @param {string} contents
 * @returns {string}
 */
function installStep(contents) {
    const lines = contents.split('\n');
    const start = stepLines(contents).install;
    const rest = lines.slice(start + 1);
    const end = rest.findIndex((line) => /^ {6}- /.test(line));

    return [lines[start], ...(end === -1 ? rest : rest.slice(0, end))].join('\n');
}

describe('токен composer не остаётся на диске после установки', () => {
    const installing = workflowNames().filter((name) => read(name).includes('ramsey/composer-install'));

    test('в репозитории есть workflow, которые ставят зависимости', () => {
        expect(installing.length).toBeGreaterThan(5);
    });

    test.each(installing)('%s снимает токен после установки', (name) => {
        const { install, auth, unset } = stepLines(read(name));

        expect(auth).toBeGreaterThan(-1);
        expect(unset).toBeGreaterThan(-1);
        expect(auth).toBeLessThan(install);
        expect(unset).toBeGreaterThan(install);
    });

    test.each(installing)('%s не пытается передать токен через env шага установки', (name) => {
        expect(installStep(read(name))).not.toMatch(/^\s*COMPOSER_AUTH:/m);
    });

    test.each(workflowNames().filter((name) => !read(name).includes('ramsey/composer-install')))(
        '%s не трогает токен composer вовсе',
        (name) => {
            const contents = read(name);

            expect(contents).not.toContain('github-oauth');
            expect(contents).not.toContain('COMPOSER_AUTH');
        },
    );
});

describe('stepLines', () => {
    test('находит все три шага и их порядок', () => {
        const contents = [
            '      - name: Configure Composer auth',
            '        run: composer config --global github-oauth.github.com ${{ secrets.X }}',
            '',
            '      - name: Install dependencies',
            '        uses: ramsey/composer-install@v4',
            '',
            '      - name: Drop the Composer token from disk',
            '        run: composer config --global --unset github-oauth.github.com',
        ].join('\n');

        expect(stepLines(contents)).toEqual({ auth: 1, install: 4, unset: 7 });
    });

    test('отдаёт -1 для отсутствующих шагов', () => {
        expect(stepLines('name: x\n')).toEqual({ auth: -1, install: -1, unset: -1 });
    });
});
