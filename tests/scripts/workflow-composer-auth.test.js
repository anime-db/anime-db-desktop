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
 *    `.claude-docs/gotchas.md`;
 * 4. сам секрет `secrets.COMPOSER_GITHUB_TOKEN` не встречается НИГДЕ, кроме шага, который ставит
 *    токен. Проверять по имени переменной недостаточно: тот же секрет можно отдать другому шагу под
 *    другим именем или дописать в `$GITHUB_ENV`, и тогда его получат все последующие шаги — то есть
 *    регрессия #971 вернётся, а проверка по `COMPOSER_AUTH:` этого не заметит.
 *
 * Проверки падают закрыто: если шаг с токеном записан в форме, которой `stepLines()` не распознаёт
 * (многострочный `run` с переносом, `composer config -g`, прямая запись `auth.json`), шаг просто не
 * найдётся и тест покраснеет — а не промолчит.
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

/**
 * Текст шага, который ставит токен, — от строки с `run:` до следующего шага того же уровня.
 *
 * @param {string} contents
 * @returns {string} пустая строка, если шага нет
 */
function authStep(contents) {
    const lines = contents.split('\n');
    const start = stepLines(contents).auth;
    if (start === -1) {
        return '';
    }

    const rest = lines.slice(start + 1);
    const end = rest.findIndex((line) => /^ {6}- /.test(line));

    return [lines[start], ...(end === -1 ? rest : rest.slice(0, end))].join('\n');
}

/**
 * @param {string} text
 * @returns {number} сколько раз встречается сам секрет
 */
function secretOccurrences(text) {
    return (text.match(/secrets\.COMPOSER_GITHUB_TOKEN/g) || []).length;
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

    test.each(installing)('%s держит секрет только в шаге, который ставит токен', (name) => {
        const contents = read(name);

        expect(secretOccurrences(contents)).toEqual(secretOccurrences(authStep(contents)));
        expect(secretOccurrences(contents)).toBe(1);
    });

    test.each(workflowNames().filter((name) => !read(name).includes('ramsey/composer-install')))(
        '%s не трогает токен composer вовсе',
        (name) => {
            const contents = read(name);

            expect(contents).not.toContain('github-oauth');
            expect(contents).not.toContain('COMPOSER_AUTH');
            expect(secretOccurrences(contents)).toBe(0);
        },
    );
});

describe('проверка по секрету краснеет на формах, которые её обходят', () => {
    const WORKFLOW = [
        '      - name: Configure Composer auth',
        '        run: composer config --global github-oauth.github.com ${{ secrets.COMPOSER_GITHUB_TOKEN }}',
        '',
        '      - name: Install dependencies',
        '        uses: ramsey/composer-install@v4',
        '',
        '      - name: Drop the Composer token from disk',
        '        run: composer config --global --unset github-oauth.github.com',
    ].join('\n');

    test('эталон проходит', () => {
        expect(secretOccurrences(WORKFLOW)).toBe(secretOccurrences(authStep(WORKFLOW)));
    });

    /** Тот же секрет другому шагу под другим именем — у тестов появляется доступ к токену. */
    test('секрет под другим именем у другого шага', () => {
        const leaky = `${WORKFLOW}\n\n      - name: Run tests\n        run: vendor/bin/phpunit\n        env:\n          GH_TOKEN: \${{ secrets.COMPOSER_GITHUB_TOKEN }}\n`;

        expect(secretOccurrences(leaky)).not.toBe(secretOccurrences(authStep(leaky)));
    });

    /** Запись в $GITHUB_ENV отдаёт секрет всем последующим шагам джобы. */
    test('секрет, дописанный в $GITHUB_ENV', () => {
        const leaky = `${WORKFLOW}\n\n      - name: Export\n        run: echo 'COMPOSER_AUTH=\${{ secrets.COMPOSER_GITHUB_TOKEN }}' >> "$GITHUB_ENV"\n`;

        expect(secretOccurrences(leaky)).not.toBe(secretOccurrences(authStep(leaky)));
    });

    /** Многострочный `composer config --global` с переносом: шаг не распознаётся, тест краснеет. */
    test('многострочная форма шага не выдаёт себя за распознанную', () => {
        const multiline = [
            '      - name: Configure Composer auth',
            '        run: |',
            '          composer config --global \\',
            '            github-oauth.github.com ${{ secrets.COMPOSER_GITHUB_TOKEN }}',
            '',
            '      - name: Install dependencies',
            '        uses: ramsey/composer-install@v4',
        ].join('\n');

        expect(stepLines(multiline).auth).toBe(-1);
        expect(authStep(multiline)).toBe('');
        expect(secretOccurrences(multiline)).not.toBe(secretOccurrences(authStep(multiline)));
    });
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
