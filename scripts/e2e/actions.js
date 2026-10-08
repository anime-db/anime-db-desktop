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
 * Клик по контролу, который отправляет форму, с ожиданием ответа на этот POST.
 *
 * Зачем отдельный хелпер: форма с `data-submit-on-change` уходит обычной навигацией, и клик по ней
 * НЕ является завершённым действием. Следующий шаг сценария — `page.goto()` или `app.evaluate()` с
 * переходом — отменяет запрос на полпути, настройка на сервер не доезжает, а сценарий падает позже
 * и совсем в другом месте. Именно так упал релизный прогон на master: в трейсе
 * `POST /settings/pagination-mode -> -1` (прерван), режим пагинации остался прежним, и сценарий
 * сломался на невидимой навигации по страницам, где про POST уже ничего не сказано.
 *
 * Ассерт вида `expect(radio).toBeChecked()` от этого не спасает: `btn-check` отмечается самим
 * кликом на той же странице, то есть проверка проходит ещё до того, как браузер уйдёт на POST.
 *
 * Локально гонка обычно выигрывается, на раннере — нет; поэтому ожидание должно быть в коде, а не
 * в удачном тайминге машины.
 */

/**
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} locator  контрол, по которому надо кликнуть
 * @param {string} pathname  путь POST-а, которого ждём (точное совпадение, без query)
 * @returns {Promise<import('@playwright/test').Response>}
 */
async function clickAwaitingPost(page, locator, pathname) {
    const [response] = await Promise.all([
        page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === pathname),
        locator.click(),
    ]);

    // Дождаться ответа недостаточно: 400 на неизвестное значение, 403 на разошедшийся CSRF-токен и
    // 500 дают ровно тот же симптом, ради которого хелпер и вводился, — настройка не применилась, а
    // сценарий падает через два шага и совсем в другом месте. Проверяется «не ошибка», а не
    // конкретный 3xx: эндпоинты отвечают по-разному — `/settings/pagination-mode` отдаёт 303
    // (`HTTP_SEE_OTHER`), переключатель синка плагина — обычный 302.
    if (response.status() >= 400) {
        throw new Error(`POST ${pathname} answered ${response.status()}; the setting was not applied.`);
    }

    return response;
}

module.exports = { clickAwaitingPost };
