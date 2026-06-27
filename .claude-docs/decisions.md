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
