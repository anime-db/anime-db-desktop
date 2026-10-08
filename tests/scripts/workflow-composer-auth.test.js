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
 * `composer config --global github-oauth.github.com <секрет>` кладёт токен в
 * `~/.config/composer/auth.json`, и он остаётся там до конца джобы — то есть и на шагах, которые
 * исполняют код: lifecycle-скрипты зависимостей в `npm ci`, тесты, прогон приложения под Xvfb,
 * запуск собранного приложения на гейте релиза. Шесть workflow делали именно так (issue #971).
 *
 * Правка выглядит безобидной и возвращается копированием шага из соседнего файла — ровно тот класс
 * регрессии, который ловится только машинно. Проверяются обе половины: глобального конфига нет
 * нигде, а каждый шаг установки зависимостей несёт токен в своём `env`.
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
 * Текст шага, который ставит зависимости, от строки с `uses:` до следующего шага того же уровня.
 *
 * @param {string} contents
 * @returns {string|null} null, если workflow зависимости не ставит
 */
function installStep(contents) {
    const lines = contents.split('\n');
    const start = lines.findIndex((line) => line.includes('uses: ramsey/composer-install'));
    if (start === -1) {
        return null;
    }

    const rest = lines.slice(start + 1);
    const end = rest.findIndex((line) => /^ {6}- /.test(line));

    return [lines[start], ...(end === -1 ? rest : rest.slice(0, end))].join('\n');
}

describe('токен composer не оседает на диске', () => {
    const names = workflowNames();

    test('в репозитории есть workflow для проверки', () => {
        expect(names.length).toBeGreaterThan(5);
    });

    test.each(workflowNames())('%s не пишет github-oauth в глобальный конфиг', (name) => {
        expect(read(name)).not.toMatch(/composer config .*github-oauth/);
    });

    test.each(workflowNames())('%s передаёт токен только шагу установки', (name) => {
        const contents = read(name);
        const step = installStep(contents);

        // Ключ, а не упоминание: про COMPOSER_AUTH говорят и комментарии рядом с шагом.
        const keys = contents.match(/^\s*COMPOSER_AUTH:/gm) || [];

        if (step === null) {
            // Workflow не ставит PHP-зависимости — токену в нём вообще нечего делать.
            expect(keys).toHaveLength(0);

            return;
        }

        expect(step).toMatch(/^\s*COMPOSER_AUTH:/m);
        expect(step).toContain('secrets.COMPOSER_GITHUB_TOKEN');
        // Токен не должен появиться вне этого шага: ни в env джобы, ни в env workflow.
        expect(keys).toHaveLength(1);
    });
});

describe('installStep', () => {
    test('берёт шаг до начала следующего', () => {
        const contents = [
            '      - name: Install dependencies',
            '        uses: ramsey/composer-install@v4',
            '        with:',
            '          working-directory: app',
            '        env:',
            '          COMPOSER_AUTH: secret',
            '',
            '      - name: Run tests',
            '        run: vendor/bin/phpunit',
        ].join('\n');

        expect(installStep(contents)).toContain('COMPOSER_AUTH');
        expect(installStep(contents)).not.toContain('phpunit');
    });

    test('отдаёт null, когда зависимости не ставятся', () => {
        expect(installStep('name: x\njobs:\n  one:\n    steps:\n      - run: echo hi\n')).toBeNull();
    });
});
