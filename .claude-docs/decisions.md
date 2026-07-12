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

## Symfony Translator и негоциация локали (issue #84)

- `symfony/translation` подключён через `framework.translator` в `app/config/packages/framework.yaml`: `default_path` указывает на существующий `app/translations/` (единый домен `messages`, без разведения по доменам), `fallbacks: [en]`. `framework.default_locale: en`.
- Опция называется `fallbacks` (список), а не `fallback_locale` — так называлась опция в старых версиях Symfony; в 8.1 при указании неверного имени контейнер падает с `InvalidConfigurationException` при сборке, а не тихо игнорирует настройку.
- Локаль резолвится в `App\EventSubscriber\LocaleSubscriber` (`kernel.request`, только `isMainRequest()`) через `Request::getPreferredLanguage($locales)`. Список `$locales` — хардкод: параметр контейнера `app.locales: ['en', 'ru']` в `services.yaml`, инжектится в подписчик как `array $locales`. Изначально список получали сканированием `app/translations/` через `glob()`, но это оказалось I/O на каждый main request — подписчик синглтон и переживает между запросами в FrankenPHP worker-режиме. Плюс сканирование всё равно не решает подключение локалей плагинов: по архитектуре плагинов их переводы будут лежать в `Resources` самого плагина и регистрироваться через конфиг бандла, а не копироваться в `app/translations/`, так что `glob()` по этой директории их никогда не увидит. Расширение `app.locales` под локали плагинов — отдельная задача, зависящая от ещё не спроектированного механизма регистрации переводов плагина (Этап 4).
- Если `app.locales` пуст, подписчик ничего не делает — остаётся `framework.default_locale`.
- MUST-правило про обязательный `{% trans %}` в Twig — в [`../CLAUDE.md`](../CLAUDE.md) §Границы.
- `native/config.js::mapOsLocaleToAppLocale()` (автоопределение локали приложения по ОС-локали, issue #85) изначально маппило на `ru` только `ru`/`ru-*`. Issue #177 расширил список префиксов, дающих `ru`, константой `RU_PREFERRED_PREFIXES = ['ru', 'be', 'kk', 'ky', 'tg', 'uz', 'hy', 'az']` — постсоветские страны (Белоруссия, Казахстан, Киргизия, Таджикистан, Узбекистан, Армения, Азербайджан), где русский широко понимаем как второй язык, даже если ОС-локаль не `ru-*`. Грузия (`ka`)/Украина (`uk`)/Молдова (`ro`) осознанно не включены — решение согласовано с автором в обсуждении issue #177, несмотря на схожий лингвистический аргумент.

## Переключатель языка в настройках (issue #86)

- Список языков переключателя строится из того же контейнерного параметра `app.locales` (`services.yaml`), что и `LocaleSubscriber` (issue #84), а не отдельным сервисом со сканированием `app/translations/`. `app/translations/` содержит только системные языки, и их всегда будет ровно два (`en`, `ru`) — остальные языки будут поставляться плагинами вместе со своими переводами, которые не копируются в общую папку. Изначально для этого был отдельный `App\Service\AvailableLocaleProvider` со сканированием каталога, но ревью PR #90 отклонило это решение как преждевременное: вопрос подключения языков плагинов будет решаться отдельно, когда появится механизм регистрации переводов плагина (Этап 4).
- Запись выбранной локали — в `App\Service\AppSettingsProvider::setLocale()`, тем же read-modify-write паттерном по `%AppData%/config.json`, что и `native/config.js` (issue #85, поле `locale`). Запись в файл вынесена в приватный `writeConfig()`, общий для всех будущих setter'ов настроек. `AppSettingsProvider` теперь читает и пишет user-facing настройки (раньше — только чтение `paginationMode`).
- `SettingsController` — один путь `/settings` на GET (`index`) и POST (`setLocale`), как у `LabelController::index()`/`add()`. POST **не редиректит**, а рендерит `settings/index.html.twig` заново тем же `Response(200)` — по условиям задачи смена языка не должна быть переходом на другой URL.
- Валидность локали при записи проверяется по списку `app.locales`, инжектируемому в контроллер тем же биндом `$locales`, что и в `LocaleSubscriber`.
- Названия языков в `<select>` (эндонимы: «Русский», «English») хранятся в `messages.ru.yaml`/`messages.en.yaml` под ключами `settings.locale.ru`/`settings.locale.en` — с одинаковыми значениями в обоих файлах, поскольку название языка не зависит от текущей локали интерфейса. Шаблон обращается к ним через `('settings.locale.'~locale)|trans({}, null, currentLocale)`, а не хардкодит карту в Twig.

## Symfony Messenger — отдельное соединение и транспорт для очереди (issue #97)

- Второе DBAL-соединение `queue` (`config/packages/doctrine.yaml`, `dbal.connections.queue`) указывает на `data/queue.db` — отдельный от `data/data.db` файл. Причина: ценность потери разная (очередь эфемерна, пользовательские данные — нет), плюс отдельный файл SQLite снимает конкуренцию по локам между HTTP-воркером FrankenPHP и будущим consumer-процессом (issue про supervisor вынесен отдельно, вне объёма).
- Единственный транспорт `async` (`config/packages/messenger.yaml`) сидит на DSN `doctrine://queue?auto_setup=0` — `auto_setup=0` осознанно: таблица `messenger_messages` создаётся явно через `bin/console messenger:setup-transports`, а не неявно при первом подключении.
- `retry_strategy` транспорта `async` задан явно (`max_retries: 3, delay: 1000, multiplier: 2, max_delay: 0`), хотя эти значения совпадают с дефолтом Symfony — сделано намеренно, чтобы поведение не менялось незаметно при апгрейде Symfony. Конкретные хендлеры могут переопределять поведение поштучно через `UnrecoverableMessageHandlingException`.
- `failure_transport` сознательно не заводится — desktop-приложение с одним конечным пользователем, некому вручную разбирать `messenger:failed:*` по расписанию.
- В продакшн `QUEUE_DATABASE_URL` и `MESSENGER_TRANSPORT_DSN` передаются через `buildEnv()` в `native/supervisor/frankenphp.js` (по аналогии с `DATABASE_URL`), путь — `paths.getQueueDbPath()` (`AppData/AnimeDB/queue.db`, плоско, как и `data.db`, без вложенной папки `data/` — это только dev-соглашение из `.env`).
- **Не входит в объём**: защита от конкурентного выполнения задач (`job_locks`), supervisor-процесс consumer'а в Electron, реальная бизнес-логика обработчиков — всё отдельными issue.

## JSON-эндпоинт переводов для JS (issue #87)

- `App\Controller\TranslationController` (`GET /translations/{locale}.json`) отдаёт `TranslatorBagInterface::getCatalogue($locale)->all('messages')` как JSON. `{locale}` валидируется по тому же `app.locales`, что и `LocaleSubscriber`/`SettingsController` — неизвестная локаль (проходящая regex-требование маршрута `[a-zA-Z]{2}`, но отсутствующая в списке) даёт `404`, а не тихий пустой каталог.
- `Symfony\Bundle\FrameworkBundle` алиасит для автовайринга только `Symfony\Contracts\Translation\TranslatorInterface`, но не `Symfony\Component\Translation\TranslatorBagInterface` (нужен для `getCatalogue()`) — пришлось добавить явный алиас `Symfony\Component\Translation\TranslatorBagInterface: '@translator'` в `services.yaml`. Без него автовайринг падает с `CannotBeAutowiredException` уже на сборке контейнера.
- Клиент (`app/public/js/translations.js`) фетчит каталог лениво (при первом вызове `trans()`/`getCatalogue()`, не сразу при загрузке страницы) и кэширует Promise в переменной модуля — повторные вызовы не бьют по сети даже до резолва первого запроса. Какую локаль подставить в URL, модуль берёт из `document.documentElement.lang`, а не переизобретает разбор `Accept-Language` на JS: `base.html.twig` теперь рендерит `<html lang="{{ app.request.locale }}">` вместо хардкода `lang="ru"` — тот хардкод был багом с момента #84 (`LocaleSubscriber` уже негоциировал `Request::getLocale()`, но `<html lang>` его не отражал), обнаруженным при проектировании этого эндпоинта, а не отдельной задачей.
- Тест `BaseTemplateRenderingTest` рендерит `base.html.twig` напрямую через `Twig\Environment::render()` в обход HTTP-цикла, поэтому `app.request` там `null`, пока в `request_stack` не запушен `Request` вручную — тот же паттерн, что уже задокументирован для `csrf_token()` (см. gotchas.md), теперь актуален и для `app.request.locale`.

## job_locks — защита от конкурентного выполнения задач (issue #98)

- Таблица `job_locks` (`job_key` PK, `pid`, `heartbeat_at`, `started_at`) в `data/queue.db` создаётся **не через Doctrine-миграцию**, а лениво (`CREATE TABLE IF NOT EXISTS`) внутри `App\Service\JobLock\JobLockService`. Причина: `doctrine/doctrine-migrations-bundle` (см. `vendor/doctrine/doctrine-migrations-bundle/src/DependencyInjection/Configuration.php`) отслеживает ровно **одно** соединение на весь проект (`doctrine_migrations.connection`, по умолчанию `default`) — существующие миграции каталога (`migrations/Version*.php`) уже привязаны к `default`/`data.db`. Переключать это соединение на `queue` означало бы либо потерять привязку каталожных миграций, либо городить `enable_service_migrations: true` + constructor-инъекцию отдельного `Connection` в класс миграции ради одной таблицы — несоразмерно сложнее самой задачи. Тот же прецедент уже есть у `messenger_messages` в том же `queue.db`: таблица создаётся явно через `bin/console messenger:setup-transports` (`auto_setup=0` в issue #97), а не через ORM-миграцию.
- `JobLockService::acquire()` — единственная точка входа, создающая лок: `INSERT` (лока нет) → при `UniqueConstraintViolationException` (SQLite `UNIQUE constraint failed`, см. `Doctrine\DBAL\Driver\API\SQLite\ExceptionConverter`) читает существующую строку → если `pid` не отвечает (`ProcessLivenessChecker::isRunning()`) **или** `heartbeat_at` протух (`now - heartbeat_at > heartbeatIntervalSeconds * staleAfterMissedHeartbeats`) — перехватывает через `UPDATE ... WHERE job_key = :jobKey AND heartbeat_at = :expectedHeartbeatAt` (оптимistic-check на прочитанное значение heartbeat, чтобы из двух гонящихся перехватчиков победил только один); иначе — `false` (лок жив).
- `App\Service\JobLock\ProcessLivenessChecker` — интерфейс, единственная реализация `WindowsProcessLivenessChecker` (`tasklist /FI "PID eq <pid>" /FO CSV /NH`, парсинг CSV вместо grep по строке — устойчивее к случайному совпадению PID с частью другого поля). Приложение только под Windows (см. architecture.md), второй реализации не будет, пока это не изменится.
- Часы — `Psr\Clock\ClockInterface` (автовайрится Symfony на `NativeClock` из коробки, `symfony/clock` тянется прод-зависимостью `symfony/messenger`), не `time()` напрямую — тесты подставляют `Symfony\Component\Clock\MockClock`.
- Не входит в объём (см. issue): использование сервиса в реальном хендлере скана хранилища — придёт вместе с самим сканом; периодичность вызова `heartbeat()` во время выполнения задачи — тоже ответственность будущего хендлера, сервис только предоставляет примитив.

## Точка расширения для Этапа 4 (плагины поиска) — issue #121

- `App\Service\Storage\Search\SearchByPluginInterface` — контракт «первое совпадение»: `find(string $name): ?SearchByPluginCandidate` (не коллекция). Единственная текущая реализация — `NullSearchByPlugin`, всегда возвращает `null`.
- `App\Service\Storage\Search\SearchByPluginChain` пробует зарегистрированные реализации по очереди и останавливается на первом непустом результате — дальше по цепочке не идёт и не сравнивает, что вернули бы остальные (см. критерии issue).
- DI-механизм: интерфейс размечен атрибутом `#[AutoconfigureTag('app.search_by_plugin')]`, поэтому **любой** класс, реализующий `SearchByPluginInterface` (включая будущие плагины Этапа 4), автоматически попадает в тег без правок `services.yaml`. `SearchByPluginChain` получает список через `#[AutowireIterator('app.search_by_plugin')]` на параметре конструктора. Этап 4 подключается, просто реализовав интерфейс — ни `SearchByPluginChain`, ни код скана (часть 5) не меняются.
- `SearchByPluginCandidate` намеренно минимален (`PluginId $pluginId`, `string $name`) — схема того, что реально возвращает внешний источник (ссылка, метаданные и т.п.), не проектируется здесь, это решение Этапа 4.
