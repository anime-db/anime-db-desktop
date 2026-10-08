# anime-db-desktop

Настольное приложение **AnimeDB v2** — программа для ведения личной коллекции
аниме на своём компьютере: каталог, сканирование хранилищ с видеофайлами,
заполнение карточек из внешних источников через плагины, поиск и фильтрация.

Это версия 2 приложения: единый устанавливаемый дистрибутив, который
разворачивает всё необходимое (веб-сервер, PHP, поисковый движок) прямо внутри
себя — пользователю не нужно ничего доустанавливать.

> **Важно:** приложение предназначено только для локального домашнего
> использования (один пользователь, localhost). В нём нет аутентификации — не
> выставляйте его в сеть.

## Как это устроено

Приложение — это Electron-оболочка вокруг Symfony-приложения. Electron отвечает
за интеграцию с ОС (окно, системный трей, жизненный цикл), а всю бизнес-логику
несёт Symfony, который выполняется встроенным FrankenPHP.

- **Electron** (`native/`) — main-процесс: окно, трей, запуск и надзор за
  дочерними процессами, передача путей и env.
- **FrankenPHP** — Go-бинарник на базе PHP 8.5 (worker mode). Отдельный
  PHP-рантайм устанавливать не нужно — он входит в состав дистрибутива
  приложения.
- **Symfony 8.1** (`app/`) — сущности, контроллеры, шаблоны (Twig + HTMX),
  плагинная система.
- **SQLite** — источник истины (данные каталога).
- **Meilisearch** (Community Edition, MIT) — поисковый индекс поверх SQLite.

Данные и рантайм-файлы хранятся в `AppData/AnimeDB/` пользователя, а не в каталоге
установки.

### Стек

Electron 35 · FrankenPHP (PHP 8.5) · Symfony 8.1 · SQLite · Meilisearch ·
Twig + HTMX · electron-builder

## Структура репозитория

```
anime-db-desktop/
├── native/      # Electron main-process: OS-интеграция, окна, трей, supervisor
│   ├── index.js         # entry point (проводка)
│   ├── paths.js         # единственный источник AppData-путей
│   ├── supervisor/      # запуск и надзор за FrankenPHP + Meilisearch
│   ├── window/          # BrowserWindow → localhost:port
│   ├── tray/            # иконка и меню трея
│   └── lifecycle/       # дирижёр: ready → supervisor → window; quit → stop
├── app/         # Symfony: бизнес-логика, HTTP, шаблоны, сущности, плагины
│   ├── public/index.php # точка входа FrankenPHP (worker mode)
│   ├── src/
│   ├── templates/       # Twig + HTMX-фрагменты
│   ├── Caddyfile        # конфиг FrankenPHP (env-плейсхолдеры порта и root)
│   └── composer.json
├── bin/         # в .gitignore; бинарники тянутся download-bins.js при сборке
├── scripts/     # download-bins.js, build.js
├── tests/       # Jest unit-тесты для native/
└── package.json # корневой, electron-builder
```

**Граница `native/` ↔ `app/`:** `native/` не знает про бизнес-логику — только как
запустить бинарники, дождаться `/health` и какие env передать.

## Требования для разработки

- Node.js (Electron 35, electron-builder 25)
- Composer (для зависимостей `app/`)
- Бинарники FrankenPHP и Meilisearch тянутся автоматически: `npm run download-bins`

Бинарники в `bin/` не хранятся в git — их скачивает `scripts/download-bins.js` под
нужную архитектуру.

## Команды

Из корня репозитория:

```bash
npm install            # зависимости Electron/сборки
npm run download-bins  # скачать FrankenPHP + Meilisearch (только x64 Windows)
npm start              # запустить приложение (electron .)
npm run build          # собрать дистрибутив (electron-builder → dist/)
npm run lint           # ESLint для native/  (lint:fix — автофикс)
npm test               # Jest (unit-тесты native/)
```

Из каталога `app/` (Symfony):

```bash
vendor/bin/phpunit     # тесты приложения
composer phpstan       # PHPStan level 8
composer cs-check      # php-cs-fixer (cs-fix — автофикс)
```

## Скриншоты интерфейса

`npm run shots` поднимает `app/` на встроенном сервере PHP, открывает страницы приложения в
Electron под Xvfb и складывает PNG-снимки в `shots/` (в `.gitignore`, в репозитории не хранятся).
Снимаются каталог, карточка аниме, добавление аниме, хранилища, настройки и маркет плагинов — в
светлой и тёмной теме.

Перед первым запуском (на чистом клоне):

```bash
composer install --working-dir=app
npm ci
npm run assets
npm run shots
```

Прогон работает не с вашей dev-базой, а с детерминированной фикстурой (`scripts/fixture/`): схема
накатывается на пустую SQLite-базу, затем команда `app:fixture:load` заливает семь записей каталога
с обложками, метку, хранилище и настройки (`config.json`). Результат копируется во временный
каталог, и в него же указывают `DATABASE_URL`, `CONFIG_PATH` и остальные пути пользовательских
данных — `data/` и `app/var/` не читаются и не меняются; после прогона каталог удаляется. Поэтому
`/anime/{id}` и `/anime/{id}/edit` снимаются и на чистом клоне, и в CI. Для своих сценариев
используйте `createIsolatedEnv()` из `scripts/fixture/` — свежая копия фикстуры стоит миллисекунды.

Команда требует Linux: снимки делаются под Xvfb (виртуальный X-сервер без физического экрана) —
на нём же собирается и Electron из `devDependencies` для этой задачи. Нужны системные пакеты
(имена — для Ubuntu 24.04; на других версиях без суффикса `t64`):

```
xvfb libatk1.0-0t64 libatk-bridge2.0-0t64 libgtk-3-0t64 libgbm1 libnss3 libasound2t64
libcups2t64 libdrm2 libxkbcommon0 libpango-1.0-0 libcairo2 libatspi2.0-0t64 libxdamage1
libxrandr2 libxcomposite1 libxfixes3 libxshmfence1
```

Без них команда падает с понятным сообщением, а не с `error while loading shared libraries`.

**Ограничения метода** — снимки с Linux-сервера не заменяют проверку на Windows-сборке:

- шрифты другие — метрики и начертания отличаются от боевых;
- снимается только видимая область окна, а не страница целиком;
- достоверны крупные заливки, фоны, состояния и раскладка; тонкие оттенки мелкого текста —
  нет, даже с отключённым субпиксельным сглаживанием.

Съёмка не покрывает нативный слой (сплэш, рамку окна, системные диалоги, трей) — они не
рендерятся веб-страницей и не могут быть сняты этим способом.

Обложки аниме (`app-media://...` в каталоге и на карточке аниме) на снимках не отрендерятся —
схема `app-media` регистрируется только в `native/protocols/app-media.js`, который подключается
из `native/lifecycle/index.js` вместе с остальным жизненным циклом приложения; `capture.js`
намеренно запускается напрямую, в обход `native/index.js` (см. комментарий в начале файла), и
эту схему не регистрирует. На снимках каталога и карточки аниме вместо обложек будет плейсхолдер
битого изображения — это ограничение метода, а не баг вёрстки.

## E2E на Playwright

Каркас сквозных проверок: приложение поднимается как в бою, а сценарии ходят по нему как
пользователь. Не заменяет `npm run shots` (у снимков своя задача и своё состояние) и не входит в CI.

```bash
npm run e2e                       # все сценарии из scripts/e2e/scenarios/*.e2e.js
npm run e2e -- -g "pick-folder"   # аргументы после `--` уходят в `playwright test`
npm run e2e:session               # поднять приложение на фикстуре и оставить работать (Ctrl+C — стоп)
```

Что требуется (только Linux): `composer install` в `app/`, `npm run assets`, `npm ci`, `php` и
`xvfb-run` в `PATH` (без `DISPLAY` команда сама перезапускается под Xvfb) и **Linux-сборка
FrankenPHP** версии из `scripts/versions.json` в `bin/frankenphp/frankenphp` — её кладёт
`npm run download-e2e-runtime` (сверяет SHA-256 из `versions.json`, повторный запуск ничего не
качает), либо путь задаётся в `E2E_FRANKENPHP_BIN`. Второй браузер не скачивается: Playwright
запускает наш Electron (`node_modules/electron`) через `_electron.launch({ executablePath })`.

**В CI набор идёт один раз на релиз** — workflow `E2E` (`.github/workflows/e2e.yml`): по тегу
`v*.*.*` и по кнопке (`workflow_dispatch`). На каждый PR он не запускается намеренно: там
работают прогон снимков и юниты. Падение прогона релиз не блокирует (публикует его отдельный
workflow `build.yml`) — до отдельного решения после нескольких релизов. При падении trace и
скриншот последнего состояния выкладываются артефактом `e2e-results`.

Как это устроено:

- сервер — боевой FrankenPHP с `app/Caddyfile` (воркер, `try_files` в Caddy), `APP_ENV=prod`,
  перед стартом выполняется `cache:warmup`; `php -S` и `scripts/shots/router.php` не используются;
- данные — копия фикстуры (`scripts/fixture`) на каждый сценарий, включая runtime-каталог
  Symfony; каталог окружения одновременно `userData` Electron (как `AppData/AnimeDB` в бою), поэтому
  `config.json` общий у PHP и native. Точка подключения: `E2E_DATA_DIR` — каталог окружения, который
  используется как есть;
- main-процесс — `scripts/e2e/main.js`: настоящие `preload.js`, IPC-обработчики `native/dialog` и
  подмена `Accept-Language` (`native/accept-language`), без супервизора (он стартует Windows-бинарники);
- взаимодействие — **только** через локаторы Playwright. `locator.click()` ждёт реальной
  кликабельности и отказывает на перекрытом элементе; `el.click()` внутри `evaluate()` /
  `executeJavaScript()` кликает сквозь оверлей с `isTrusted=false`, поэтому ESLint запрещает его в
  `scripts/e2e/` (правило проверено тестом `tests/scripts/e2e-framework.test.js`);
- нативные диалоги — подмена в настоящем main-процессе через `electronApp.evaluate()`
  (`scripts/e2e/dialogs.js`: `stubOpenDialog`, `stubOpenDialogCancelled`, `stubMessageBox`);
- живучесть — таймаут на сценарий 90 с (`E2E_TIMEOUT_MS`), при падении в `e2e-results/` остаются
  trace и скриншот последнего состояния (видео нет: `recordVideo` в Electron требует ffmpeg — второй загрузки — и без него виснет), итоговый вердикт называет упавшие сценарии, код выхода ненулевой.

Сценарии первой партии (`scripts/e2e/scenarios/*.e2e.js`): инлайн-редактор карточки, метки,
галерея кадров, место показа ошибки заливки, список каталога (фильтр, пагинация, пустое
состояние), подтверждение удаления, `/settings/backup`, `/storage/new`, переключение языка.

**Метка для матрицы покрытия.** Каждый сценарий приложения объявляется через `covers()` из
`scripts/e2e/coverage.js`. Сознательное исключение — 5 сценариев самого каркаса в
`scripts/e2e/scenarios/smoke.e2e.js`: они проверяют инфраструктуру (запуск, доверенный клик,
prod-страница ошибки, подмена диалога), а не функциональность приложения, и в матрицу не входят.

Метки — обычные теги Playwright, их отдаёт
`npx playwright test -c scripts/e2e/playwright.config.js --list --reporter=json` в поле `tags`:

```js
test('название', covers({ routes: ['/anime/{id}'], features: ['inline-editor'] }), async ({ page, session }) => { ... });
// теги: @route:/anime/{id}  @feature:inline-editor
```

Маршрут — путь Symfony с `{плейсхолдерами}`, фича — имя в kebab-case; нужен хотя бы один из двух.
Сценарий, которому нужна кнопка «Заполнить из источника», включает офлайн-плагин:
`test.use({ sourcePlugin: true })` (`scripts/e2e/plugins.js`, сеть не нужна).

`e2e:session` печатает URL приложения и CDP-endpoint (`chromium.connectOverCDP(endpoint)`).
Диалоги в такой сессии не подменены.

## Платформы

Только **Windows x64** (минимум Windows 10). macOS и Linux не поддерживаются:
FrankenPHP не даёт x32-сборку под Windows, а целевая аудитория — только Windows.

## Документация для разработчиков

- [CLAUDE.md](CLAUDE.md) — краткая точка входа (команды, границы, workflow)
- [.claude-docs/index.md](.claude-docs/index.md) — таблица маршрутизации по докам
- [.claude-docs/architecture.md](.claude-docs/architecture.md) — монорепо,
  граница native/app, FrankenPHP, Meilisearch, пути
- [.claude-docs/conventions.md](.claude-docs/conventions.md) — стиль кода и команды
- [.claude-docs/gotchas.md](.claude-docs/gotchas.md) — нетривиальные ловушки

## Лицензия

GPL-3.0-or-later. См. [LICENSE](LICENSE). Лицензия относится к исходникам этого репозитория;
сторонние бинарники, которые скачивает `scripts/download-bins.js`, распространяются под своими
лицензиями — см. [resources/third-party-licenses/README.md](resources/third-party-licenses/README.md).
