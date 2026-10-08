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
 * У приложения нет composer-зависимостей из приватных репозиториев: `app/composer.json` не
 * объявляет ни одного `repositories`, а `anime-db/plugin-contracts` приезжает с Packagist
 * zipball-ом с `api.github.com`. Токена для установки не нужно, и секрета `COMPOSER_GITHUB_TOKEN`
 * у репозитория нет — в логе CI шаг `composer config --global github-oauth.github.com` печатался
 * без значения (маски `***` нет, подставлять нечего).
 *
 * Поэтому возвращать такой шаг нельзя, и дело не только в мёртвом коде. Пустой токен **хуже**
 * отсутствующего: composer считает его учётными данными, предъявляет GitHub и получает отказ —
 * `Could not authenticate against github.com`. Воспроизводится локально одной командой:
 *
 *     COMPOSER_AUTH='{"github-oauth":{"github.com":""}}' composer install   # падает
 *     composer install                                                      # работает
 *
 * В CI это особенно коварно: при тёплом кэше composer сеть не нужна вовсе, поэтому ubuntu-джобы
 * остаются зелёными, а падает только та, у которой кэш холодный (так упала Windows-джоба
 * `runtime-parity`). Подробности — `.claude-docs/gotchas.md`.
 *
 * Тест держит одно утверждение: ни один workflow не трогает composer-авторизацию — ни шагом
 * `composer config`, ни `COMPOSER_AUTH`, ни самим секретом. Без YAML-парсера: в репозитории нет
 * YAML-зависимости (то же обоснование, что в tests/scripts/build-workflow-extensions.test.js).
 */

'use strict';

const fs   = require('fs');
const path = require('path');

const workflowsDir = path.join(__dirname, '..', '..', '.github', 'workflows');

/**
 * GitHub Actions запускает и `*.yml`, и `*.yaml` — страж, который смотрит только на первое,
 * пропустил бы возврат шага в новом файле со вторым расширением.
 *
 * @param {string} name
 * @returns {boolean}
 */
function isWorkflowFile(name) {
    return /\.ya?ml$/.test(name);
}

/** @returns {string[]} имена файлов всех workflow */
function workflowNames() {
    return fs.readdirSync(workflowsDir).filter(isWorkflowFile).sort();
}

/**
 * @param {string} name
 * @returns {string}
 */
function read(name) {
    return fs.readFileSync(path.join(workflowsDir, name), 'utf8');
}

/**
 * Формы, которыми composer-авторизация попадает в workflow. Ищется подстрока, а не строка целиком:
 * многострочный `run` с переносом, `-g` вместо `--global`, `env` на шаге и запись в `$GITHUB_ENV`
 * ловятся одинаково.
 *
 * @param {string} contents
 * @returns {string[]} найденные формы
 */
function authTraces(contents) {
    return [
        ['github-oauth', /github-oauth/],
        ['COMPOSER_AUTH', /COMPOSER_AUTH/],
        ['secrets.COMPOSER_GITHUB_TOKEN', /secrets\.COMPOSER_GITHUB_TOKEN/],
        ['auth.json', /auth\.json/],
    ].filter(([, pattern]) => pattern.test(contents)).map(([label]) => label);
}

describe('workflow не настраивают composer-авторизацию', () => {
    test('в репозитории есть workflow для проверки', () => {
        expect(workflowNames().length).toBeGreaterThan(5);
    });

    test.each(workflowNames())('%s', (name) => {
        expect(authTraces(read(name))).toEqual([]);
    });
});

describe('isWorkflowFile', () => {
    test.each(['ci.yml', 'ci.yaml', 'e2e.yaml'])('%s — workflow', (name) => {
        expect(isWorkflowFile(name)).toBe(true);
    });

    test.each(['README.md', 'ci.yml.bak', 'ci.yamlx', 'yml'])('%s — не workflow', (name) => {
        expect(isWorkflowFile(name)).toBe(false);
    });
});

describe('authTraces', () => {
    test.each([
        ['однострочный composer config', '        run: composer config --global github-oauth.github.com ${{ secrets.COMPOSER_GITHUB_TOKEN }}'],
        ['короткий -g', '        run: composer config -g github-oauth.github.com x'],
        ['перенос строки внутри run', '        run: |\n          composer config --global \\\n            github-oauth.github.com x'],
        ['env на шаге установки', '        env:\n          COMPOSER_AUTH: \'{"github-oauth":{"github.com":"x"}}\''],
        ['запись в $GITHUB_ENV', '        run: echo "COMPOSER_AUTH=x" >> "$GITHUB_ENV"'],
        ['прямая запись auth.json', '        run: echo "{}" > ~/.config/composer/auth.json'],
    ])('ловит форму: %s', (_name, snippet) => {
        expect(authTraces(snippet)).not.toEqual([]);
    });

    test('не срабатывает на обычном шаге установки', () => {
        const step = [
            '      - name: Install dependencies',
            '        uses: ramsey/composer-install@v4',
            '        with:',
            '          working-directory: app',
        ].join('\n');

        expect(authTraces(step)).toEqual([]);
    });
});
