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

/*
 * Ставит data-bs-theme на <html> по сохранённой настройке темы (issue #638) — цветовые схемы
 * Bootstrap 5.3 включаются этим атрибутом, сам он ниоткуда не появляется.
 *
 * Почему скриптом, а не через $color-mode-type: media-query в Sass: медиазапросная сборка
 * прибивает тему к системной намертво, и переключатель в настройках не смог бы её переопределить.
 *
 * data-theme-preference на <html> — значение AppSettingsProvider::getThemePreference(), отданное
 * синхронно через ThemePreferenceExtension (base.html.twig), без отдельного запроса к бэкенду.
 * При "light"/"dark" тема выставляется сразу и без подписки на системную. При "system" или
 * отсутствии атрибута (значение не распознано) — как раньше: определяем по
 * prefers-color-scheme и подписываемся на его изменение, чтобы смена системной темы применялась
 * на лету.
 *
 * Почему отдельный файл, а не инлайн в шаблоне: Content-Security-Policy задаёт script-src 'self'
 * (native/content-security-policy.js), инлайновый <script> ею блокируется. Подключён в <head>
 * синхронно, до отрисовки, иначе на каждой загрузке мигал бы светлый фон.
 */
(function () {
    var preference = document.documentElement.dataset.themePreference;

    if (preference === 'light' || preference === 'dark') {
        document.documentElement.setAttribute('data-bs-theme', preference);

        return;
    }

    var query = window.matchMedia('(prefers-color-scheme: dark)');

    function apply() {
        document.documentElement.setAttribute('data-bs-theme', query.matches ? 'dark' : 'light');
    }

    apply();
    query.addEventListener('change', apply);
})();
