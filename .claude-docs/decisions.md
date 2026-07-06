---
tags: [memory/repo, decisions]
---

# Принятые решения

## Стек

**Electron + Symfony 8.1 + FrankenPHP + Meilisearch.**

- Electron — нативный слой (Windows, окна, трей)
- Symfony — бизнес-логика на PHP (знакомый стек, богатая экосистема)
- FrankenPHP — PHP-рантайм встроен в Go-бинарник (один файл, worker mode, нет Apache/Nginx)
- Meilisearch — поиск с морфологией и typo-tolerance

## Только Windows x64 на старте

FrankenPHP и Meilisearch не имеют x32-сборок для Windows. macOS — незначительная ЦА. Linux — не рассматривается.

## Caddyfile — Путь B (статический файл под git)

`app/Caddyfile` хранится под git. Порт и root передаются через `{env.APP_PORT}` / `{env.APP_ROOT}`.

**Отклонённые варианты:**
- Путь A (`php-server` cli) — поведение `SERVER_NAME` задокументировано только косвенно, ограниченный набор директив Caddy
- Путь C (только env, без файла) — embedded Caddyfile не гарантирован в standalone-бинарнике

## php.ini — шаблон под git, динамический ini в AppData

`bin/php/php.ini.template` под git. При первом запуске `frankenphp.js` пишет `AppData/AnimeDB/php.ini` с подстановкой часового пояса. `PHPRC` указывает на папку (`AppData/AnimeDB/`), не на файл.

## SQLite + JSON-колонки (хранилище)

SQLite — источник истины. Гибкость метаданных через JSON-колонки (`metadata JSON`). SQLite поддерживает `json_extract`, `->`, `->>` с версии 3.38. Doctrine поддерживает тип `json` из коробки.

**Отклонено:** MongoDB, CouchDB — избыточны и тяжелы для встроенного приложения.

## Meilisearch Community Edition (MIT)

Enterprise версия под BSL-1.1 — несовместима с GPLv3. Всегда скачивать только Community. Явно указывать в `download-bins.js`.

## Meilisearch — миграция индекса через post-update старт (Вариант B)

Нормальный старт приложения не проверяет и не мигрирует индекс. Смена версии Meilisearch — только вместе с обновлением приложения. Post-update старт: Splash Screen → вайп LMDB → переиндексация из SQLite.

**Отклонён Вариант A** (фоновый swap): два одновременных процесса Meilisearch + удвоение памяти — избыточно для личной коллекции.

Обнаружение версии: `meilisearch.js` сравнивает `versions.json` (рядом с бинарником) с `AppData/AnimeDB/meilisearch/VERSION` (Meilisearch пишет сам).

## Graceful shutdown — OS-level kill, без HTTP-эндпоинта

HTTP-shutdown в Symfony не подходит: FrankenPHP — Go-процесс, он управляет PHP-воркерами напрямую, HTTP-запрос в Symfony не имеет доступа к их lifecycle. SQLite (WAL) и Meilisearch (LMDB) crash-safe.

Паттерн: SIGTERM → 500ms → SIGKILL.

## Плагинная система — один плагин на вендора (мульти-плагин)

В v1 каждая функция была отдельным Composer-пакетом (6 пакетов для Shikimori). В v2 — один пакет, функции включаются/выключаются через настройки. Плагины изолированы от основной схемы.

## Обновление приложения — electron-updater

Реализуется в Этапе 6. Обычный старт не блокируется миграциями. Миграции и переиндексация — только в post-update старте.

Порядок post-update: (1) проверка версии Meilisearch → вайп если нужно; (2) Doctrine migrations; (3) переиндексация Meilisearch; (4) healthcheck → окно.

## Именование директорий

`native/` (не `electron/`) — ось «нативное ↔ веб», стандартная для Electron/Tauri. `app/` (не `symfony/`) — это приложение, а не фреймворк.

## Чистый лист (не миграция из v1)

Старый код (anime-db + app-bundle + catalog-bundle + monitor) используется только как справочник. Монорепо пишется с нуля.

## APP_SECRET — генерация при первом запуске, хранение в AppData

`APP_SECRET` генерируется Electron при первом запуске (`crypto.randomBytes(32).toString('hex')`), сохраняется в `AppData/AnimeDB/config.json`, передаётся в FrankenPHP через `buildEnv()`. Каждая установка получает свой уникальный секрет.

**Отклонено:** генерация в NSIS-инсталлере — инсталлер не знает AppData конкретного пользователя Windows.

## Бинарники — фиксированные версии, обновление только с релизом приложения

FrankenPHP и Meilisearch скачиваются с официальных GitHub Releases (`dunglas/frankenphp`, `meilisearch/meilisearch`). Версии зафиксированы в `scripts/versions.json`. Обновление бинарников проходит через тестирование и поставляется только вместе с обновлением приложения — не автоматически.

`download-bins.js` скачивает версии из `scripts/versions.json`, кладёт в `bin/frankenphp/` и `bin/meilisearch/`. Только x64 Windows (Community Edition для Meilisearch — MIT-лицензия).

## Сборка дистрибутива — GitHub Actions, windows-latest

`.exe`-инсталлер собирается в GitHub Actions на `windows-latest` runner. NSIS-инсталлер требует Windows. Сборка запускается вручную или по тегу релиза. Артефакт публикуется в GitHub Releases.

## Этап 2 — архитектурные решения

### Splash Screen — прогресс и статус

Splash Screen отображает и визуальный прогресс, и текстовый статус (что именно грузится). Убрать всегда проще, чем добавить позже.

### Связь бэкенда с Electron — WebSocket через FrankenPHP/Caddy

Для коммуникации Symfony → Electron используется WebSocket (не polling, не IPC через stdout). Мотивация: та же шина нужна для push-уведомлений из фоновых процессов Symfony в UI — например, «сканирование завершено», «найдено N новых серий».

Реализация:
- WebSocket поднимается нативно через FrankenPHP/Caddy (встроенная поддержка, не нужна сторонняя PHP-библиотека)
- Отдельный порт от HTTP (порт выбирается динамически, как и HTTP-порт, через `findFreePort`)
- Слушает только на `127.0.0.1` — достаточная изоляция для локального приложения с одним пользователем, дополнительная аутентификация не нужна
- Electron-рендерер подключается как обычный браузерный WebSocket-клиент
- Нативный слой (`native/`) подключается через Node.js `ws` если нужно обновлять иконку трея по событиям бэкенда

**Отклонено:** polling `/health` для иконки трея — слишком узко, не масштабируется на push-события в UI.

### HTMX — npm-пакет, не под git, не CDN

Приложение работает offline — CDN не вариант. HTMX устанавливается как npm-зависимость (`htmx.org`), при сборке копируется в `app/public/js/htmx.min.js`. Файл добавляется в `.gitignore` (генерируется при сборке, не хранится в репозитории).

Аналогично бинарникам FrankenPHP/Meilisearch: в git — нет, в дистрибутив — да.

### HtmxSubscriber — пишем сами, без бандла

Кастомный `EventSubscriber` в Symfony определяет тип запроса (заголовок `HX-Request`) и отдаёт фрагмент или полный layout. Кода немного, лишняя зависимость не нужна.

### Порядок Graceful Shutdown

1. Останавливаем FrankenPHP (SIGTERM → 500ms → SIGKILL)
2. Останавливаем Meilisearch (SIGTERM → 500ms → SIGKILL)

FrankenPHP завершается первым — новых HTTP-запросов к Meilisearch не будет, безопасно останавливать индекс следом.

## Ротация логов (issue #16)

### Symfony / Monolog

- Обработчик: `rotating_file` (RotatingFileHandler Monolog) в prod-окружении.
- Лимит файлов: **14** (две недели), имя — `app-YYYY-MM-DD.log`, `deprecation-YYYY-MM-DD.log`.
- Путь: `%kernel.logs_dir%/app.log` → в prod это `APP_RUNTIME_DIR/log/app-YYYY-MM-DD.log`.
- Ограничение по размеру: **не реализовано** — Monolog RotatingFileHandler не поддерживает, только по дате.
- Оборачивается в `fingers_crossed` (пишет только при ошибке уровня error и выше).

### Нативный слой (FrankenPHP, Meilisearch)

- Реализация: общий модуль `native/supervisor/logrotate.js` — только встроенный Node.js `fs`.
- Формат имён: `frankenphp-YYYY-MM-DD.log`, `meilisearch-YYYY-MM-DD.log`.
- Лимит файлов: **7** (неделя) для каждого процесса.
- Очистка: **при старте приложения** (`start()` в каждом supervisory-модуле), не в реальном времени.
- Дата в имени файла — локальное время (по часовому поясу системы пользователя).
- Файл открывается в режиме append (`flags: 'a'`); при перезапуске процесса через backoff — тот же поток.

## Протокол `app-media://` для обложек и изображений аниме (issue #68)

- URL-схема `app-media://anime/{id}/{filename}` резолвится в `%AppData%/media/{id}/{filename}` через `protocol.handle()` (Electron 35; `registerFileProtocol` не используется — устаревающий API). Схема регистрируется как privileged в `native/protocols/app-media.js` синхронно при загрузке модуля, до `app.whenReady()` — обязательное требование Electron.
- Скоуп сознательно ограничен обложками/изображениями аниме. Видео не резолвится через протокол вообще (открывается системным проводником), статические ассеты приложения отдаются напрямую Caddy.
- `{filename}` не фиксировано — уникализируется таймстемпом при каждой записи, что позволяет `Cache-Control: public, max-age=31536000, immutable` без проверки на чтение.
- Защита от path traversal — двухуровневая: `parseAppMediaUrl()` отклоняет `{id}`, не являющийся положительным целым, и `{filename}`, в котором после `decodeURIComponent` появился `/` или `\`; `resolveAppDataMediaPath()` дополнительно резолвит итоговый путь через `path.resolve()` и проверяет префикс `%AppData%/media/` + `path.sep` — это ловит и абсолютные пути, подставленные вместо имени файла.
- MIME-тип — по расширению файла (whitelist: webp/jpg/jpeg/png/gif), без сниффинга содержимого — файлы в AppData полностью под контролем приложения.
- **Не проверено эмпирически в этой задаче**: поддержка WebP в GD собранного FrankenPHP (`gd_info()['WebP Support']` / `imagewebp()`). Песочница реализации — Linux без Windows-бинарника FrankenPHP (`bin/frankenphp/` не скачан, скачивается только `frankenphp-windows-x86_64.zip`); локальный системный PHP не тот же бинарник и не показателен. Формат WebP выбран как целевой согласно тексту задачи; при недоступности — запасной вариант JPEG/PNG. Проверить командой вида `frankenphp.exe php-cli -r "var_dump(function_exists('imagewebp'));"` на Windows-сборке до реального использования ресайза в код записи обложек.
