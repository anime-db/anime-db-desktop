---
tags: [memory/repo, architecture]
---

# Архитектура anime-db-desktop

## Структура монорепо

```
anime-db-desktop/
├── native/         # Electron main-process: OS, окна, трей, lifecycle, supervisor
│   ├── index.js            # entry point ("main" в package.json) — только проводка
│   ├── paths.js            # единственный источник AppData-путей
│   ├── supervisor/
│   │   ├── index.js        # оркестрирует FrankenPHP + Meilisearch → два порта + ready
│   │   ├── frankenphp.js   # спавн, env (PHPRC, DATABASE_URL, MEILISEARCH_URL), restart
│   │   ├── meilisearch.js  # спавн, --db-path, --http-port, restart
│   │   ├── port.js         # поиск свободного порта (общий для обоих)
│   │   └── healthcheck.js  # HTTP-пинг /health (общий для обоих)
│   ├── window/index.js     # BrowserWindow, loadURL(localhost:port)
│   ├── tray/index.js       # иконка трея, контекстное меню
│   └── lifecycle/index.js  # дирижёр: ready → supervisor → window; quit → stop
├── app/            # Symfony 8.1: бизнес-логика, HTTP, шаблоны, сущности, плагины
│   ├── public/index.php    # точка входа FrankenPHP (worker mode)
│   ├── src/
│   │   ├── Controller/HealthController.php  # GET /health → 200 OK
│   │   ├── Entity/
│   │   ├── Repository/
│   │   ├── Plugin/         # плагинная система
│   │   └── Kernel.php
│   ├── templates/          # Twig + HTMX-фрагменты
│   ├── var/                # в продакшн → AppData/AnimeDB/var/ (APP_RUNTIME_DIR)
│   ├── Caddyfile           # статический, под git, env-плейсхолдеры {env.APP_PORT} и {env.APP_ROOT}
│   └── composer.json
├── bin/            # в .gitignore; тянутся download-bins.js при сборке
│   ├── frankenphp/frankenphp.exe
│   ├── meilisearch/meilisearch.exe
│   └── php/php.ini.template    # под git; динамический ini пишется в AppData
├── scripts/
│   ├── download-bins.js    # качает бинарники (только x64 Windows)
│   └── build.js
├── tests/          # Jest unit-тесты для native/
└── package.json    # корневой, electron-builder
```

## Граница native/ ↔ app/

**`native/` не знает про бизнес-логику.** Она знает только:
- как запустить бинарник FrankenPHP и дождаться `/health`
- как запустить Meilisearch и дождаться его healthcheck
- какие env-переменные передать

Связь модулей только через `lifecycle/`:
- `supervisor/`, `window/`, `tray/` не зависят друг от друга напрямую
- `paths.js` импортируется напрямую из `supervisor/` (конфигурация, не поведение)

## FrankenPHP

**FrankenPHP — Go-бинарник со встроенным PHP 8.5** (static-php-cli). Отдельного PHP-рантайма нет.

| Параметр       | Значение                                                                                       |
|----------------|------------------------------------------------------------------------------------------------|
| PHP            | 8.5 (дефолт build-static.sh с v1.12.4)                                                         |
| Windows-сборка | `frankenphp-windows-x86_64.zip` — только x64                                                   |
| ZTS            | да (`--enable-zts`)                                                                            |
| Расширения     | статически вкомпилированы (pdo_sqlite, mbstring, curl, intl, opcache, gd, imagick, ~50 других) |

**php.ini:** `bin/php/php.ini.template` под git. При первом запуске `frankenphp.js` копирует его в `AppData/AnimeDB/php.ini`, подставляя часовой пояс (`Intl.DateTimeFormat().resolvedOptions().timeZone`). FrankenPHP стартует с `PHPRC=AppData/AnimeDB` (путь к папке, не к файлу).

**Caddyfile:** статический `app/Caddyfile` под git. Порт и root — через `{env.APP_PORT}` и `{env.APP_ROOT}`. Electron передаёт их через env дочернего процесса.

```caddyfile
{
    frankenphp
    auto_https off
}

:{env.APP_PORT} {
    root * {env.APP_ROOT}/public
    php_server {
        worker ./public/index.php
    }
}
```

**Запуск:** `frankenphp.exe run --config app/Caddyfile` с `cwd=app/`

**Env для FrankenPHP:**

| Переменная      | Значение                          |
|-----------------|-----------------------------------|
| APP_PORT        | найденный свободный порт (≥8000)  |
| APP_ROOT        | `paths.getAppRootDir()`           |
| APP_ENV         | `prod`                            |
| DATABASE_URL    | `sqlite:///AppData/.../data.db`   |
| PHPRC           | `paths.getPhpIniDir()` (папка!)   |
| APP_RUNTIME_DIR | `paths.getRuntimeDir()`           |
| MEILISEARCH_URL | добавляется в Задаче 8            |
| MEILISEARCH_KEY | добавляется в Задаче 8            |

## Meilisearch

| Параметр       | Значение                                        |
|----------------|-------------------------------------------------|
| Лицензия       | **Community Edition (MIT)** — обязательно       |
| Windows-сборка | `meilisearch-windows-amd64.exe` — только x64    |
| Язык           | Rust, таргет `x86_64-pc-windows-msvc`           |
| Хранилище      | LMDB (AppData/AnimeDB/meilisearch/)             |
| PHP SDK        | `meilisearch/meilisearch-php` v1.16.1, PHP ^8.1 |

SQLite — источник истины. Meilisearch — поисковый индекс поверх него.

**Master key:** генерируется один раз как UUID, хранится в `AppData/AnimeDB/meilisearch-key.txt`. При перезапусках читается оттуда.

**Версионирование индекса:** Meilisearch строго версионирует LMDB (индекс одной версии нельзя открыть другой). При обновлении приложения: вайп `AppData/AnimeDB/meilisearch/` → пустой старт → переиндексация из SQLite.

## paths.js — AppData-пути

Единственный источник всех путей. Импортируется напрямую из supervisor-модулей.

| Функция                   | Путь                                  |
|---------------------------|---------------------------------------|
| `getDbPath()`             | `AppData/AnimeDB/data.db`             |
| `getMeilisearchDataDir()` | `AppData/AnimeDB/meilisearch/`        |
| `getPhpIniPath()`         | `AppData/AnimeDB/php.ini`             |
| `getPhpIniDir()`          | `AppData/AnimeDB/`                    |
| `getAppRootDir()`         | `<repo>/app/`                         |
| `getRuntimeDir()`         | `AppData/AnimeDB/var/`                |
| `getMeilisearchKeyPath()` | `AppData/AnimeDB/meilisearch-key.txt` |

## supervisor — жизненный цикл дочерних процессов

Порядок запуска (в `lifecycle/index.js`):
1. `supervisor.start()` → FrankenPHP и Meilisearch стартуют параллельно
2. `waitForHealth(port)` → пинг `/health` каждые 200ms, таймаут 30s
3. Вернуть порты → `createWindow(frankenphpPort)`

Backoff при рестарте: `[1000, 2000, 4000, 8000, 16000, 30000]` ms.

Graceful shutdown: SIGTERM → 500ms → SIGKILL. Оба хранилища (SQLite WAL, LMDB) crash-safe.

## app/ — Symfony 8.1

FrankenPHP стартует `public/index.php` в **worker mode** — PHP загружается один раз и остаётся в памяти.

`var/` в продакшн → `AppData/AnimeDB/var/` через `APP_RUNTIME_DIR` (Electron → env → Symfony).

`src/Plugin/` — плагинная система. Один плагин на вендора, функции включаются/выключаются через настройки плагина. Плагины не имеют доступа к основной схеме SQLite — работают через `plugin_data` или отдельный SQLite-файл.

## Платформы

Только Windows x64. macOS/Linux не поддерживаются (FrankenPHP не даёт x32 Windows, ЦА только Windows).

**Минимальная поддерживаемая версия — Windows 10** (зафиксировано 2026-07-10, ранее не было записано явно). Влияет на выбор системных вызовов из `native/`/PHP: нельзя закладываться на `wmic.exe` (Microsoft убирает его из системы по умолчанию начиная с Windows 11 24H2), но можно — на `powershell.exe` (встроен начиная с Windows 7, безопасно ниже нашего минимума).
