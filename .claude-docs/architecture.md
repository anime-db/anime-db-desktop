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

**Single-instance lock и защита от процессов-сирот (issue #390):** `lifecycle/index.js` вызывает
`app.requestSingleInstanceLock()` первым делом; при отказе — `app.quit()`, второй запуск не трогает
дочерние процессы уже работающей копии. `app.on('second-instance', ...)` поднимает существующее
окно (`show()`/`focus()`, плюс `restore()` если оно было свёрнуто).

Каждый из четырёх дочерних процессов (`frankenphp.js`, `meilisearch.js`, `qbittorrent.js`,
`messenger-consumer.js`) через общий `native/supervisor/pid-tracker.js` пишет свой PID в
`AppData/AnimeDB/var/pids/<name>.pid` при спавне и удаляет файл при штатном `stop()`. Перед каждым
`start()` вызывается `pidTracker.killOrphan()` — если файл остался от предыдущего сеанса (падение,
принудительное завершение, инсталлятор, закрывший только `AnimeDB.exe`), PID проверяется через
`tasklist` (совпадение имени образа с ожидаемым бинарником — сам PID мог быть переиспользован ОС) и
при совпадении убивается через `taskkill /F`. No-op не на Windows.

Дополнительно `supervisor.killSync()` (агрегирует `killSync()` каждого модуля, `child.kill('SIGKILL')`
синхронно) вызывается из `process.on('exit')` в `lifecycle/index.js` — страховка для путей
завершения, которые не проходят через `before-quit` (например `process.exit()` откуда-то ещё).
`process.on('SIGTERM'/'SIGINT')` ведут туда же, куда и штатный выход из трея.

## app/ — Symfony 8.1

FrankenPHP стартует `public/index.php` в **worker mode** — PHP загружается один раз и остаётся в памяти.

`var/` в продакшн → `AppData/AnimeDB/var/` через `APP_RUNTIME_DIR` (Electron → env → Symfony).

`src/Plugin/` — плагинная система. Один плагин на вендора, функции включаются/выключаются через настройки плагина. Плагины не имеют доступа к основной схеме SQLite — работают через `plugin_data` или отдельный SQLite-файл.

### Виджеты плагинов (issue #212)

Виджет плагина (`AnimeDb\PluginContracts\Widget\EntryWidgetInterface`/`CatalogWidgetInterface`) грузится
асинхронно через HTMX: `anime/show.html.twig` рендерит один `<div hx-get hx-trigger="load">` на
каждый активный виджет из `App\Service\Plugin\EntryWidgetRegistry::findAllActive()`, а
`App\Controller\PluginWidgetController` отвечает на `GET /plugin/{pluginId}/widget/{widgetName}?entryId=`
отдельным запросом на виджет — сбой одного виджета не блокирует страницу и другие виджеты.

Регистрация виджетов — тот же паттерн, что `FillerRegistry`: `_instanceof` в `services.yaml`
тэгирует `EntryWidgetInterface`/`CatalogWidgetInterface` (пакет `anime-db/plugin-contracts`
read-only, `#[AutoconfigureTag]` там не повесить), а `EntryWidgetRegistry`/`CatalogWidgetRegistry`
собирают их через `#[AutowireIterator(..., indexAttribute: 'id')]`. В отличие от Filler (один
класс на плагин), у виджетов один класс на виджет, поэтому индексный ключ — составной
`"{pluginId}:{widgetName}"`, а не просто `PluginId`; будущий plugin manager (issues
#218/#220-224) должен регистрировать сервис виджета под таким id. До тех пор оба реестра пусты в
проде. Каждый виджет переключается независимо через `features.{widgetName}` в `plugins.json`
(не общий флаг `features.widget`).

Для entry-виджета контроллер сначала резолвит внешний id через `Anime::getExternalId($pluginId, $widget)`
(issue #211) — это одновременно и прогрев кэша, и получение параметра для `render()`. Контракт
`render(?string $externalId): string` (`anime-db/plugin-contracts` v0.3, issue #21 в этом пакете)
принимает резолвнутый id напрямую, включая `null`, когда источник к записи не привязан; localId
записи виджет не получает вовсе. Empty-state при `null` — забота самого виджета (пустая строка
скрывает слот, либо, например, CTA), контроллер такое решение не принимает и хост-заглушки для
этого случая больше нет. Исключение из `render()` не пробрасывается — контроллер логирует его и
отдаёт `plugin/_widget_error.html.twig` (200, с кнопкой retry на тот же URL через `hx-get`).

**`plugin/_widget_list.html.twig`** — необязательный хелпер для частого случая «виджет = список
записей», переиспользующий классы `.anime-card` из `css/anime-list.css` для визуальной
консистентности с каталогом. Официальные плагины (не обязаны) рендерят его сами через
`Twig\Environment`, если тянут в контейнер. Контракт: переменная `items` — список
`{thumbnail: string|null, title: string, subtitle: string|null, url: string}`. Любая страница,
включающая ответ виджета, должна сама подключить `css/anime-list.css` (так уже сделано в
`anime/show.html.twig`).

Ответ виджета — обычный кэшируемый GET, полностью определяемый URL (`pluginId`, `widgetName`,
`entryId`), без сессии/cookie; успешный ответ (включая `null`-externalId, отрендеренный самим
виджетом) несёт `Cache-Control: public, max-age=300`, фрагмент error — без кэша.

## Платформы

Только Windows x64. macOS/Linux не поддерживаются (FrankenPHP не даёт x32 Windows, ЦА только Windows).

**Минимальная поддерживаемая версия — Windows 10** (зафиксировано 2026-07-10, ранее не было записано явно). Влияет на выбор системных вызовов из `native/`/PHP: нельзя закладываться на `wmic.exe` (Microsoft убирает его из системы по умолчанию начиная с Windows 11 24H2), но можно — на `powershell.exe` (встроен начиная с Windows 7, безопасно ниже нашего минимума).
