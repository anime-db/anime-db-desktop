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

`app/Caddyfile` хранится под git. Root — рантайм-плейсхолдер `{env.APP_ROOT}` (разбирается при обработке запроса). Порт в адресе сайта — препроцессорный `{$APP_PORT}` / `{$WS_PORT}`: адрес разбирается на этапе адаптации конфига, до рантайма, где `{env.X}` не подставляется и валит адаптацию (issue #532). Оба server-блока — `bind 127.0.0.1`; WS-блок без хоста в адресе, чтобы не словить автоматический TLS-listener (хост-литерал в адресе — матчер `Host`, а не bind-адрес).

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

Реализуется в Этапе 6.

**Отменено (issue #392):** «Обычный старт не блокируется миграциями, миграции — только в post-update старте». У «это post-update старт» нет надёжного признака — версия приложения им не является. Вместо этого Doctrine-миграции проверяются на **каждом** старте: дешёвая `doctrine:migrations:up-to-date --fail-on-unregistered` (`native/supervisor/migrations.js`) определяет, есть ли неприменённые миграции, и только тогда запускается реальный `doctrine:migrations:migrate` — с бэкапом `data.db` через `VACUUM INTO` перед прогоном. См. [.claude-docs/gotchas.md](gotchas.md) за подробностями отказоустойчивости (fail-closed, обработка даунгрейда).

Порядок старта: (1) проверка версии Meilisearch → вайп если нужно; (2) Doctrine migrations (каждый старт, реальный прогон — только если есть что применять); (3) переиндексация Meilisearch (только если был вайп); (4) healthcheck → окно.

## Именование директорий

`native/` (не `electron/`) — ось «нативное ↔ веб», стандартная для Electron/Tauri. `app/` (не `symfony/`) — это приложение, а не фреймворк.

## Чистый лист (не миграция из v1)

Старый код (anime-db + app-bundle + catalog-bundle + monitor) используется только как справочник. Монорепо пишется с нуля.

## APP_SECRET — генерация при первом запуске, хранение в AppData

`APP_SECRET` генерируется Electron при первом запуске (`crypto.randomBytes(32).toString('hex')`), сохраняется в `AppData/AnimeDB/config.json`, передаётся всем дочерним PHP-процессам через общий `buildCommonEnv()` (`native/supervisor/env.js`). Каждая установка получает свой уникальный секрет.

**Отклонено:** генерация в NSIS-инсталлере — инсталлер не знает AppData конкретного пользователя Windows.

## Бинарники — фиксированные версии, обновление только с релизом приложения

FrankenPHP и Meilisearch скачиваются с официальных GitHub Releases (`dunglas/frankenphp`, `meilisearch/meilisearch`). Версии зафиксированы в `scripts/versions.json`. Обновление бинарников проходит через тестирование и поставляется только вместе с обновлением приложения — не автоматически.

`download-bins.js` скачивает версии из `scripts/versions.json`, кладёт в `bin/frankenphp/` и `bin/meilisearch/`. Только x64 Windows (Community Edition для Meilisearch — MIT-лицензия).

## Сборка дистрибутива — GitHub Actions, windows-latest

`.exe`-инсталлер собирается в GitHub Actions на `windows-latest` runner. NSIS-инсталлер требует Windows. Сборка запускается вручную или по тегу релиза. Артефакт публикуется в GitHub Releases.

## `asar: false` — не asarUnpack (issue #388)

`app/**` читает FrankenPHP как отдельный OS-процесс — asar-виртуализация Electron/Node на него не распространяется, каталог обязан быть настоящими файлами на диске. `bin/**` — исполняемые файлы, `child_process.spawn` не может запустить бинарник изнутри архива. Эти два каталога — фактически весь полезный объём сборки; `asarUnpack` для них оставил бы упакованными только `native/` и `resources/` (и то и другое одинаково исправно читается изнутри asar через fs/`file://`), то есть выгода от упаковки маргинальна, а cost — правка путей в `native/paths.js` и трёх супервизорах (`.asar.unpacked`). Выбран `asar: false` как более простой и не более рискованный вариант; `native/paths.js` и супервизоры не тронуты.

`package.json` → `build.files` до этой задачи не включал `scripts/versions.json`, хотя `meilisearch.js` читает его в рантайме (`path.join(__dirname, '..', '..', 'scripts', 'versions.json')`) — в собранном инсталляторе файла не было бы вообще. Добавлен точечно (`scripts/versions.json`), не весь `scripts/**` — `build.js`/`download-bins.js` нужны только на этапе сборки.

Версия релиза берётся из `GITHUB_REF_NAME` (дефолтная env-переменная GitHub Actions, не требует правки workflow) в `scripts/build.js#syncVersionFromTag`, который выполняется как `prebuild` перед `electron-builder`. Стемпается только при совпадении с `v\d+\.\d+\.\d+` (тег релиза) — для `workflow_dispatch` без тега или локального `npm run build` версия из `package.json` не трогается.

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
- `{filename}` не фиксировано — уникализируется таймстемпом при каждой записи, что позволяет `Cache-Control: public, max-age=31536000, immutable` без проверки на чтение. **Исправлено по факту кода (аудит цикла issue #503–#508):** таймстемпа в имени не было никогда — `HttpPluginMediaDownloader::download()` с самого начала (issue #231) строит имя как `sha1($url)`, а после issue #504 ещё и с фиксированным расширением: `sha1($url).'.webp'`. Запись выше описывала замысел, а не реализацию. Детерминированность имени несущая, менять её нельзя: на ней держится дедупликация повторно присланного плагином URL (`PluginAnimeDataMerger::applyImages()`, `DownloadAnimeMediaMessageHandler`) и ранний выход `is_file()`, который экономит повторное скачивание. **Следствие для кеширования:** обоснование `immutable` через уникальность имени не работает — держится оно на другом. Один URL даёт одно имя и одни байты (переэнкод детерминирован), а повторное скачивание отсекается до сети, поэтому расхождения не возникает. Единственный сценарий, в котором оно возможно: файл удалён с диска вручную, а источник за это время подменил картинку по тому же URL — тогда Chromium продолжит показывать старую копию из своего кеша до истечения года. Прежняя схема с таймстемпом от этого защищала бы, нынешняя — нет; принято осознанно.
- Защита от path traversal — двухуровневая: `parseAppMediaUrl()` отклоняет `{id}`, не являющийся положительным целым, и `{filename}`, в котором после `decodeURIComponent` появился `/` или `\`; `resolveAppDataMediaPath()` дополнительно резолвит итоговый путь через `path.resolve()` и проверяет префикс `%AppData%/media/` + `path.sep` — это ловит и абсолютные пути, подставленные вместо имени файла.
- MIME-тип — по расширению файла (whitelist: webp/jpg/jpeg/png/gif), без сниффинга содержимого — файлы в AppData полностью под контролем приложения.
- **Сужено (issue #505)**: whitelist выше отражает состояние на момент решения issue #68. После врезки `App\Service\Media\ImageNormalizer` в `HttpPluginMediaDownloader::download()` (issue #504) все изображения, попадающие в `%AppData%/media/`, переэнкодятся в WebP перед записью на диск, поэтому whitelist в `native/protocols/app-media.js::MIME_TYPES` сужен до одной записи `.webp`. Остальные расширения теперь отдаются как `application/octet-stream` через фолбэк `getMimeType()`. Исходная формулировка (webp/jpg/jpeg/png/gif) оставлена без изменений как исторический след решения #68.
- **Снято (issue #496)**: пункт ниже утверждал «не проверено эмпирически в этой задаче: поддержка WebP в GD собранного FrankenPHP» — формулировка оставлена как исторический след, не удалена.
- Для **Linux**-сборки FrankenPHP поддержка WebP в GD проверена эмпирически и подтверждена: `gd_info()` даёт `WebP Support: true` (заодно `AVIF: true`, `JPEG: true`). Для **Windows**-сборки, на которой собирается приложение, статус остаётся открытым до прогона `gd_info()`/`imagewebp()` в Windows CI — GD там подгружается как отдельная DLL (`ext/php_gd.dll`, как `intl`/`mbstring`/остальные расширения), которая теперь входит в курируемый набор `FRANKENPHP_FILES` (`scripts/download-bins.js`) и объявлена директивой `extension=gd` в `bin/php/php.ini.template` и `REQUIRED_EXTENSION_DIRECTIVES` (`native/php-ini.js`), а также как `ext-gd` в `require` `app/composer.json`. `objdump -p` на `ext/php_gd.dll` из релиза frankenphp v1.12.4 подтверждает, что она импортирует только `php8ts.dll` и системные библиотеки (`GDI32`, `USER32`, `KERNEL32`, `VCRUNTIME140`, `api-ms-win-crt-*`) — отдельных `libwebp.dll`/`libpng`/`libjpeg` в архиве нет, кодеки слинкованы внутрь самой DLL, довозить больше нечего. Ресайз через GD2 в код ещё не реализован — эта задача обеспечивает только рантайм-доступность расширения тому, что уже решено.

## Symfony Translator и негоциация локали (issue #84)

- `symfony/translation` подключён через `framework.translator` в `app/config/packages/framework.yaml`: `default_path` указывает на существующий `app/translations/` (единый домен `messages`, без разведения по доменам), `fallbacks: [en]`. `framework.default_locale: en`.
- Опция называется `fallbacks` (список), а не `fallback_locale` — так называлась опция в старых версиях Symfony; в 8.1 при указании неверного имени контейнер падает с `InvalidConfigurationException` при сборке, а не тихо игнорирует настройку.
- Локаль резолвится в `App\EventSubscriber\LocaleSubscriber` (`kernel.request`, только `isMainRequest()`) через `Request::getPreferredLanguage($locales)`. Изначально список получали сканированием `app/translations/` через `glob()`, но это оказалось I/O на каждый main request — подписчик синглтон и переживает между запросами в FrankenPHP worker-режиме. Плюс сканирование всё равно не решало подключение локалей плагинов: по архитектуре плагинов их переводы лежат в собственном `translations/` плагина, а не копируются в `app/translations/`, так что `glob()` по этой директории их никогда бы не увидел. Список зафиксировали хардкодом — параметр контейнера `app.locales: ['en', 'ru']` в `services.yaml` — а расширение под локали плагинов отложили как отдельную задачу, зависящую от ещё не спроектированного механизма регистрации переводов плагина (Этап 4).
- **Ограничение снято (issue #453)**: механизм регистрации переводов плагина появился — `PluginLoader::translationPaths()` (issue #373) уже подключает `translations/` включённых плагинов типов `translation`/`integration` в `framework.translator.paths`. `LocaleSubscriber`, `SettingsController` и `TranslationController` больше не читают `app.locales` напрямую — все трое инжектят `App\Service\Plugin\AvailableLocalesProvider`, который на `all()` отдаёт `app.locales` (встроенные `en`/`ru`) плюс `locales` из манифестов включённых плагинов типа `PluginType::Translation` (источник истины — поле манифеста, уже провалидированное `ManifestValidator::validateLocales()`, а не имена файлов в `translations/`; `PluginType::Integration` в список локалей не добавляет: **с контракта `v0.15` объявить `locales` он вправе** (issue #62 в `anime-db-plugin-contracts`), но его каталоги живут в собственном домене `<plugin-id>.<locale>.yaml`, а переключатель языка читает домен `messages` — фильтр по типу в `AvailableLocalesProvider` держится на домене, а не на запрете в контракте, и снимать его нельзя). `app.locales` остался в `services.yaml` только как базовый список, инжектируемый в `AvailableLocalesProvider` через `$coreLocales`.
- Это **не** воспроизводит отклонённое решение из issue #84/PR #90: там проблемой был `glob()` по `app/translations/` — сканирование каталога и парсинг файлов на каждый main request. `AvailableLocalesProvider::all()` вместо этого пересчитывает список на каждый вызов через `InstalledPluginsRegistry::enabled()` — это не тот же класс I/O (`require` уже готового, предварительно распарсенного `installed-plugins.php`, а не `glob()` + парсинг манифестов каждого плагина), но и не бесплатно: `LocaleSubscriber` вызывает `all()` на каждый main request, и каждый такой вызов стоит один `require` индекса плюс одно чтение и `json_decode()` `plugins.json` (после ревизии 2 ниже — фиксированная стоимость независимо от числа установленных плагинов). Это осознанное послабление ограничения issue #84 «никакого I/O на request-path», а не его сохранение в силе — цена ненулевая и явно записана здесь, а не выведена из ложной аналогии: `PluginLoader::translationPaths()`, вызывающий тот же `InstalledPluginsRegistry::enabled()`, вызывается из `Kernel::configureContainer()`, то есть один раз при сборке контейнера в worker-режиме, а **не** на каждый request — предыдущая редакция этой записи ошибочно утверждала обратное (обнаружено в ревью PR #455, см. ревизию 2 ниже).
- **Ревизия (PR #455, ревью claude[bot])**: первая версия `AvailableLocalesProvider` кешировала результат `all()` в памяти на весь процесс воркера и инвалидировала кеш по in-process событию `App\Event\InstalledPluginsChangedEvent`, которое диспатчили `InstalledPluginsRegistry::reconcile()` и `PluginsConfigStore::updatePluginSettings()`. Это оказалось некорректно в multi-worker FrankenPHP (`app/Caddyfile` поднимает пул воркеров без `num_threads`, у каждого — изолированная память, как прямо документирует `WsPublisher` про необходимость `ws_events`/SQLite для кросс-воркерных событий): `updatePluginSettings()` (путь enable/disable) не рассылает `workers.reload` всем воркерам, поэтому Symfony-событие инвалидировало кеш только у того одного воркера, что обработал запрос-мутацию — остальные держали устаревший список локалей до случайного рестарта. Кеш и событие убраны целиком (класс `App\Event\InstalledPluginsChangedEvent` удалён), `all()` теперь считает список заново на каждый вызов — см. предыдущий пункт про то, почему это не возвращает проблему issue #84.
- **Ревизия 2 (PR #455, ревью peter-gribanov)**: до этой правки `InstalledPluginsRegistry::readIndex()` резолвил `enabled` каждого плагина через `PluginsConfigStore::getPluginSettings()` — полное чтение и `json_decode()` `plugins.json` **на каждый установленный плагин**, то есть N чтений одного и того же файла на один main request при N плагинах, а не фиксированная стоимость, как утверждал предыдущий пункт. Заменено на новый `PluginsConfigStore::getAllSettings()`, читающий файл один раз за вызов `readIndex()` независимо от числа плагинов. Кеш по `filemtime()` (два `stat()` вместо чтения+разбора, когда файл не менялся между вызовами) рассматривался как способ снизить и эту фиксированную стоимость и отклонён: `filemtime()` в PHP имеет разрешение только в целую секунду, а принятый здесь критерий (см. `AvailableLocalesProviderTest::testAllObservesOnDiskChangesOnTheNextCallWithNoCacheToInvalidate`/`testDisablingAPluginRemovesItsLocaleForAnIndependentProviderInstance`) требует, чтобы мутация плагина была видна уже на следующем вызове того же воркера без какого-либо кеша — две мутации в пределах одной секунды такой кеш не заметил бы, что тихо вернуло бы ровно ту проблему устаревания, ради которой убрали in-process кеш из ревизии выше. Пересчёт на каждый вызов остаётся в силе как осознанный компромисс: один `require` индекса плюс одно чтение `plugins.json` на main request.
- **Цепочка фолбэка стала зависеть от локали (issue #538).** `framework.translator.fallbacks: [en]` остаётся в `framework.yaml`, но это только пол — для консольных команд и прямых вызовов контроллеров в тестах, минующих подписчик. На каждом главном запросе `LocaleSubscriber` выставляет `[ближайший(локаль), en]` через `App\Service\NearestBuiltInLocale`. Мотив: `native/` по решению issue #177 уже откатывался на `ru` для постсоветских префиксов (`ru be kk ky tg uz hy az`), а `app/` — на `en`, то есть один экран показывал два разных «ближайших языка». Побочный и более важный выигрыш — домен плагина: он существует только внутри плагина, ядро в него ничего не подмешивает, поэтому плагин, везущий только `ru`, на интерфейсе `kk` отдавал **сырые идентификаторы ключей**, а не английский текст; с цепочкой `[ru, en]` тот же пользователь видит русский.
  - Таблица префиксов **намеренно продублирована** в `native/config.js` и `NearestBuiltInLocale` — тот же приём, что у `App\Service\LocaleDirection`, зеркалящего RTL-таблицу: список меняется близко к никогда, а тянуть его из Node в PHP в рантайме дороже.
  - Вызов `setFallbackLocales()` **безусловный и стоит до раннего `return`** при пустом списке локалей: `Translator` в worker-режиме не пересоздаётся между запросами, и запрос, пропустивший вызов, унаследовал бы цепочку предыдущего.
  - Пересборки каталога на каждый запрос нет: `setFallbackLocales()` кладёт список в `cacheVary['fallback_locales']`, а путь файла кеша скомпилированного каталога считается хешем от `cacheVary` — у каждого набора фолбэков свой файл.
  - Сервис инжектится по id `translator.default` с типом конкретного `Symfony\Component\Translation\Translator`, а не по интерфейсу: `setFallbackLocales()` не объявлен ни в одном интерфейсе. Алиас `translator` в dev резолвится в `DataCollectorTranslator`, который форвардит вызов через `__call()` — работало бы, но PHPStan сквозь магию не видит, а `instanceof`-проверка дала бы фичу, работающую в проде и молча выключенную в деве.
  - `SettingsController::setLocale()` после записи локали пересчитывает цепочку и синхронизирует локаль запроса: `native/accept-language.js` подставляет `Accept-Language` из `config.json` **до** записи, поэтому на самом POST смены языка подписчик уже отработал по старой локали, и без пересчёта ключ, отсутствующий в новом каталоге, резолвился бы через старую цепочку.
- Если `app.locales` (после слияния с локалями плагинов) пуст, подписчик ничего не делает — остаётся `framework.default_locale`.
- MUST-правило про обязательный `{% trans %}` в Twig — в [`../CLAUDE.md`](../CLAUDE.md) §Границы.
- `native/config.js::mapOsLocaleToAppLocale()` (автоопределение локали приложения по ОС-локали, issue #85) изначально маппило на `ru` только `ru`/`ru-*`. Issue #177 расширил список префиксов, дающих `ru`, константой `RU_PREFERRED_PREFIXES = ['ru', 'be', 'kk', 'ky', 'tg', 'uz', 'hy', 'az']` — постсоветские страны (Белоруссия, Казахстан, Киргизия, Таджикистан, Узбекистан, Армения, Азербайджан), где русский широко понимаем как второй язык, даже если ОС-локаль не `ru-*`. Грузия (`ka`)/Украина (`uk`)/Молдова (`ro`) осознанно не включены — решение согласовано с автором в обсуждении issue #177, несмотря на схожий лингвистический аргумент.

## Переключатель языка в настройках (issue #86)

- Список языков переключателя строится из того же источника, что и `LocaleSubscriber` (issue #84) — `App\Service\Plugin\AvailableLocalesProvider::all()`, а не отдельным сервисом со сканированием `app/translations/`. Изначально для этого был отдельный `App\Service\AvailableLocaleProvider` со сканированием каталога, но ревью PR #90 отклонило это решение как преждевременное: вопрос подключения языков плагинов будет решаться отдельно, когда появится механизм регистрации переводов плагина (Этап 4). Issue #453 снял это ограничение тем же `AvailableLocalesProvider`, что и `LocaleSubscriber`/`TranslationController` — см. запись выше.
- Запись выбранной локали — в `App\Service\AppSettingsProvider::setLocale()`, тем же read-modify-write паттерном по `%AppData%/config.json`, что и `native/config.js` (issue #85, поле `locale`). Запись в файл вынесена в приватный `writeConfig()`, общий для всех будущих setter'ов настроек. `AppSettingsProvider` теперь читает и пишет user-facing настройки (раньше — только чтение `paginationMode`).
- `SettingsController` — один путь `/settings` на GET (`index`) и POST (`setLocale`), как у `LabelController::index()`/`add()`. POST **не редиректит**, а рендерит `settings/index.html.twig` заново тем же `Response(200)` — по условиям задачи смена языка не должна быть переходом на другой URL. **Заменено (issue #558)**: см. запись ниже — POST теперь отвечает 303 (PRG), а не рендерит на месте.
- Валидность локали при записи проверяется по `AvailableLocalesProvider::all()`, тому же источнику, что и в `LocaleSubscriber` (issue #453).
- Названия языков в `<select>` (эндонимы: «Русский», «English») хранились в `messages.ru.yaml`/`messages.en.yaml` под ключами `settings.locale.ru`/`settings.locale.en` — с одинаковыми значениями в обоих файлах, поскольку название языка не зависит от текущей локали интерфейса. Шаблон обращался к ним через `('settings.locale.'~locale)|trans({}, null, currentLocale)`, а не хардкодил карту в Twig.
- **Заменено (issue #461)**: подход выше не пережил того, что список локалей стал динамическим (issue #453, см. выше) — ядро не может держать переводческий ключ под каждую локаль, которую способен привезти плагин, а плагин не может дописать ключ в каталог ядра. Подпись теперь резолвится эндонимом языка, который отдаёт сама ICU по коду локали (`\Locale::getDisplayName($locale, $locale)`), напрямую внутри `App\Service\LocaleEndonymResolver` и Twig-функции `locale_endonym()`. Ключи `settings.locale.ru`/`settings.locale.en` удалены из обоих каталогов. Если ICU не смогла распознать код (локаль не распознана) — резолвер логирует `warning` и отдаёт код локали как есть, вместо падения. `ext-intl` — обязательное PHP-расширение (`composer.json`), поэтому отдельного интерфейса-шва под подмену реализации здесь нет, в отличие от `FreeSpaceProvider`: там абстракция нужна из-за недоступной для мока функции `disk_free_space()`, а не из-за опциональности расширения.

## Symfony Messenger — отдельное соединение и транспорт для очереди (issue #97)

- Второе DBAL-соединение `queue` (`config/packages/doctrine.yaml`, `dbal.connections.queue`) указывает на `data/queue.db` — отдельный от `data/data.db` файл. Причина: ценность потери разная (очередь эфемерна, пользовательские данные — нет), плюс отдельный файл SQLite снимает конкуренцию по локам между HTTP-воркером FrankenPHP и будущим consumer-процессом (issue про supervisor вынесен отдельно, вне объёма).
- Единственный транспорт `async` (`config/packages/messenger.yaml`) сидит на DSN `doctrine://queue?auto_setup=0` — `auto_setup=0` осознанно: таблица `messenger_messages` создаётся явно через `bin/console messenger:setup-transports`, а не неявно при первом подключении.
- `retry_strategy` транспорта `async` задан явно (`max_retries: 3, delay: 1000, multiplier: 2, max_delay: 0`), хотя эти значения совпадают с дефолтом Symfony — сделано намеренно, чтобы поведение не менялось незаметно при апгрейде Symfony. Конкретные хендлеры могут переопределять поведение поштучно через `UnrecoverableMessageHandlingException`.
- `failure_transport` сознательно не заводится — desktop-приложение с одним конечным пользователем, некому вручную разбирать `messenger:failed:*` по расписанию.
- В продакшн `QUEUE_DATABASE_URL` и `MESSENGER_TRANSPORT_DSN` передаются через общий `buildCommonEnv()` (`native/supervisor/env.js`, issue #391) — единственный источник переменных окружения для всех дочерних PHP-процессов (по аналогии с `DATABASE_URL`), путь — `paths.getQueueDbPath()` (`AppData/AnimeDB/queue.db`, плоско, как и `data.db`, без вложенной папки `data/` — это только dev-соглашение из `.env`).
- **Не входит в объём**: защита от конкурентного выполнения задач (`job_locks`), supervisor-процесс consumer'а в Electron, реальная бизнес-логика обработчиков — всё отдельными issue.

## JSON-эндпоинт переводов для JS (issue #87)

- `App\Controller\TranslationController` (`GET /translations/{locale}.json`) отдаёт `TranslatorBagInterface::getCatalogue($locale)->all('messages')` как JSON. `{locale}` валидируется по тому же `AvailableLocalesProvider::all()`, что и `LocaleSubscriber`/`SettingsController` (issue #453) — неизвестная локаль (проходящая regex-требование маршрута `[a-zA-Z]{2}`, но отсутствующая в списке) даёт `404`, а не тихий пустой каталог. Для локали плагина каталог собирается тем же вызовом: `framework.translator.paths` уже включает `translations/` плагина через `PluginLoader::translationPaths()`, так что единственное, что раньше держало плагинную локаль недостижимой — это как раз проверка `in_array($locale, $this->locales, true)` по старому, не расширяемому без рестарта списку.
- `Symfony\Bundle\FrameworkBundle` алиасит для автовайринга только `Symfony\Contracts\Translation\TranslatorInterface`, но не `Symfony\Component\Translation\TranslatorBagInterface` (нужен для `getCatalogue()`) — пришлось добавить явный алиас `Symfony\Component\Translation\TranslatorBagInterface: '@translator'` в `services.yaml`. Без него автовайринг падает с `CannotBeAutowiredException` уже на сборке контейнера.
- Клиент (`app/assets/js/translations.js`) фетчит каталог лениво (при первом вызове `trans()`/`getCatalogue()`, не сразу при загрузке страницы) и кэширует Promise в переменной модуля — повторные вызовы не бьют по сети даже до резолва первого запроса. Какую локаль подставить в URL, модуль берёт из `document.documentElement.lang`, а не переизобретает разбор `Accept-Language` на JS: `base.html.twig` теперь рендерит `<html lang="{{ app.request.locale }}">` вместо хардкода `lang="ru"` — тот хардкод был багом с момента #84 (`LocaleSubscriber` уже негоциировал `Request::getLocale()`, но `<html lang>` его не отражал), обнаруженным при проектировании этого эндпоинта, а не отдельной задачей.
- Тест `BaseTemplateRenderingTest` рендерит `base.html.twig` напрямую через `Twig\Environment::render()` в обход HTTP-цикла, поэтому `app.request` там `null`, пока в `request_stack` не запушен `Request` вручную — тот же паттерн, что уже задокументирован для `csrf_token()` (см. gotchas.md), теперь актуален и для `app.request.locale`.

## job_locks — защита от конкурентного выполнения задач (issue #98)

- Таблица `job_locks` (`job_key` PK, `pid`, `heartbeat_at`, `started_at`) в `data/queue.db` создаётся **не через Doctrine-миграцию**, а лениво (`CREATE TABLE IF NOT EXISTS`) внутри `App\Service\JobLock\JobLockService`. Причина: `doctrine/doctrine-migrations-bundle` (см. `vendor/doctrine/doctrine-migrations-bundle/src/DependencyInjection/Configuration.php`) отслеживает ровно **одно** соединение на весь проект (`doctrine_migrations.connection`, по умолчанию `default`) — существующие миграции каталога (`migrations/Version*.php`) уже привязаны к `default`/`data.db`. Переключать это соединение на `queue` означало бы либо потерять привязку каталожных миграций, либо городить `enable_service_migrations: true` + constructor-инъекцию отдельного `Connection` в класс миграции ради одной таблицы — несоразмерно сложнее самой задачи. Тот же прецедент уже есть у `messenger_messages` в том же `queue.db`: таблица создаётся явно через `bin/console messenger:setup-transports` (`auto_setup=0` в issue #97), а не через ORM-миграцию.
- `JobLockService::acquire()` — единственная точка входа, создающая лок: `INSERT` (лока нет) → при `UniqueConstraintViolationException` (SQLite `UNIQUE constraint failed`, см. `Doctrine\DBAL\Driver\API\SQLite\ExceptionConverter`) читает существующую строку → если `pid` не отвечает (`ProcessLivenessChecker::isRunning()`) **или** `heartbeat_at` протух (`now - heartbeat_at > heartbeatIntervalSeconds * staleAfterMissedHeartbeats`) — перехватывает через `UPDATE ... WHERE job_key = :jobKey AND heartbeat_at = :expectedHeartbeatAt` (оптимistic-check на прочитанное значение heartbeat, чтобы из двух гонящихся перехватчиков победил только один); иначе — `false` (лок жив).
- `App\Service\JobLock\ProcessLivenessChecker` — интерфейс, единственная реализация `WindowsProcessLivenessChecker` (`tasklist /FI "PID eq <pid>" /FO CSV /NH`, парсинг CSV вместо grep по строке — устойчивее к случайному совпадению PID с частью другого поля). Приложение только под Windows (см. architecture.md), второй реализации не будет, пока это не изменится.
- Часы — `Psr\Clock\ClockInterface` (автовайрится Symfony на `NativeClock` из коробки, `symfony/clock` тянется прод-зависимостью `symfony/messenger`), не `time()` напрямую — тесты подставляют `Symfony\Component\Clock\MockClock`.
- Не входит в объём (см. issue): использование сервиса в реальном хендлере скана хранилища — придёт вместе с самим сканом; периодичность вызова `heartbeat()` во время выполнения задачи — тоже ответственность будущего хендлера, сервис только предоставляет примитив.

## Сидинг демо-данных мастера установки, шаг 7/7 (issue #181)

- `App\Service\Install\SampleAnimeSeeder` — обычный сервис (не `doctrine/doctrine-fixtures-bundle`, не консольная команда): демо-каталог — это данные по явному согласию реального пользователя в проде, а не dev/test-фикстуры. Список из 7 тайтлов (title/`AnimeType`/эпизоды или длительность/студия/жанры/имя файла обложки) — приватный `const SAMPLES` в самом сервисе, без внешнего YAML/JSON — данные фиксированы и меняются только вместе с кодом.
- Вызывается контроллером `App\Controller\OnboardingSeedDemoController` (`POST /onboarding/seed-demo`) — тем же CSRF-паттерном, что и `StorageNewController`/`AnimeLabelController`. Контроллер всегда редиректит на `home_index`; онбординг-баннер сам перестаёт рендериться, как только каталог непуст (см. `HomeController`, issue #179) — отдельный флаг "сидинг выполнен" не нужен.
- Не защищено от повторного вызова: кнопка «Загрузить демо-данные» физически недостижима после первого успешного сидинга, поскольку баннер рендерится только при пустом каталоге (issue #181, вопрос об идемпотентности закрыт на этапе аналитики).
- Каждая запись получает метку `Sample` (find-or-create по имени, как в `AnimeLabelController::findOrCreateLabel()`) — пользователь фильтрует/удаляет вручную, отдельного механизма массовой очистки нет осознанно (см. issue).
- Студия ищется/создаётся через новый `App\Repository\StudioRepository::findOneByName()` (по аналогии с `LabelRepository`) — без `#[ORM\Entity(repositoryClass: ...)]` (см. гочу про `ServiceEntityRepository`/`DefaultRepositoryFactory` выше). Локальный кэш "имя студии → сущность" на время одного `seed()` обязателен: три из семи тайтлов делят студию Madhouse, а репозиторный `findOneBy()` не видит ещё не сфлашенную (persisted, но не flushed) сущность в том же unit of work — без кэша сервис создал бы три отдельные строки Madhouse вместо одной.
- Обложки — новые файлы под `app/public/sample/` (сами файлы не входят в это изменение, добавляются отдельно — см. `public/sample/README.md`). Сидинг копирует файл в `%AppData%/media/{id}/` тем же способом, что `AnimeTypeMigrator` двигает медиа-директорию — сырые `copy()`/`mkdir()`, без новой зависимости (`symfony/filesystem` не подключался). Отсутствие файла-источника не считается ошибкой: аниме создаётся без обложки, весь сидинг не откатывается.
- Порядок `flush()`: сначала одним вызовом персистятся все 7 `Anime` (+ студии/метка), чтобы получить автоинкрементные `id`, только потом копируются обложки (путь `%AppData%/media/{id}/` зависит от `id`) и делается второй `flush()` для `cover`.

## Точка расширения для Этапа 4 (плагины поиска) — issue #121

- `App\Service\Storage\Search\SearchByPluginInterface` — контракт «первое совпадение»: `find(string $name): ?SearchByPluginCandidate` (не коллекция). Единственная текущая реализация — `NullSearchByPlugin`, всегда возвращает `null`.
- `App\Service\Storage\Search\SearchByPluginChain` пробует зарегистрированные реализации по очереди и останавливается на первом непустом результате — дальше по цепочке не идёт и не сравнивает, что вернули бы остальные (см. критерии issue).
- DI-механизм: интерфейс размечен атрибутом `#[AutoconfigureTag('app.search_by_plugin')]`, поэтому **любой** класс, реализующий `SearchByPluginInterface` (включая будущие плагины Этапа 4), автоматически попадает в тег без правок `services.yaml`. `SearchByPluginChain` получает список через `#[AutowireIterator('app.search_by_plugin')]` на параметре конструктора. Этап 4 подключается, просто реализовав интерфейс — ни `SearchByPluginChain`, ни код скана (часть 5) не меняются.
- `SearchByPluginCandidate` намеренно минимален (`PluginId $pluginId`, `string $name`) — схема того, что реально возвращает внешний источник (ссылка, метаданные и т.п.), не проектируется здесь, это решение Этапа 4.

## plugins.json — общий файл настроек/credentials плагинов (issue #219)

- `App\Service\Plugin\PluginsConfigStore` (`app/src/Service/Plugin/`) — один общий JSON-файл на все установленные плагины, ключ верхнего уровня — `(string) PluginId`. Хранит OAuth refresh-токены, endpoint-конфигурацию, feature-флаги. **Без шифрования** — защита на уровне прав ФС AppData, как у существующих SQLite-файлов. Короткоживущие access-токены сюда не пишутся — они в обычном кеше с TTL, это только описано в докблоке класса, а не проверяется кодом (проверить состав ключей нечем — хранилище общего назначения).
- Путь прокинут по тому же паттерну, что `CONFIG_PATH`/`MEDIA_DIR`: `native/paths.js::getPluginsConfigPath()` → `AppData/AnimeDB/plugins.json`; передаётся в оба процесса, которые пишут в файл, — `native/supervisor/frankenphp.js` (HTTP-воркер) и `native/supervisor/messenger-consumer.js` (фоновый consumer) — через `PLUGINS_CONFIG_PATH`; в Symfony заходит как параметр `app.plugins_config_path` (dev-дефолт `var/plugins.json`) и биндится как `$pluginsConfigPath` в `services.yaml`.
- API: `getPluginSettings(PluginId): array` — без лока, читает `plugins.json` целиком (atomic rename при записи и так гарантирует consistent-снимок); `updatePluginSettings(PluginId, callable $modifier): void` — единственная точка записи, весь цикл читать → изменить → сериализовать → temp-файл → `rename()` → снять лок держится под `flock(LOCK_EX)`. Наружу не отдаётся отдельных «читать всё / писать всё» методов — это специально не даёт вызывающему коду самому собрать небезопасную read-then-write-без-лока последовательность.
- **Лок держится не на самом `plugins.json`, а на соседнем `plugins.json.lock`.** См. гочу ниже — блокировка самого файла данных не работает вместе с atomic-rename-записью.

## config.json — атомарная запись через AppConfigStore (issue #342)

- `App\Service\AppConfigStore` (`app/src/Service/`) — калька `PluginsConfigStore` для `%AppData%/config.json`: `read(): array` без лока (atomic rename и так даёт consistent-снимок), `update(callable $modifier): void` — единственная точка записи, весь цикл read → modify → serialize → temp-файл → `rename()` держится под non-blocking `flock(LOCK_EX | LOCK_NB)` с bounded-retry (10 попыток × 5мс), при исчерпании — `AppConfigStoreLockedException`. Лок — на соседнем `config.json.lock`, не на самом `config.json` (та же причина, что у `PluginsConfigStore`: `rename()` подменяет inode на каждой записи).
- `AppSettingsProvider` и `ProxyConfigProvider` больше не имеют собственных `readConfig()/writeConfig()` — оба композируют `AppConfigStore` внутри себя, получая его через DI (конструктор принимает `AppConfigStore`, а не `string $configPath`, — см. коммит `refactor(config): inject AppConfigStore via DI instead of new-ing it`, автовайринг работает через тот же биндинг `$configPath` в `services.yaml`, которым уже пользуется сам `AppConfigStore`). Любая новая запись `config.json` обязана идти через `AppConfigStore::update()` — отдельного temp+rename в обход него быть не должно.
- **Инвариант, на котором держится PHP-only лок: `native/config.js` пишет `config.json` только при первом запуске.** `getOrCreateAppSecret()`/`getOrCreateLocale()` вызываются из `native/supervisor/frankenphp.js` и `native/supervisor/messenger-consumer.js` синхронно **до** спавна соответствующего PHP-процесса (`buildEnv()`), то есть ни один PHP-процесс не существует в момент, когда Node ещё пишет файл. После первого запуска Node только читает (`getLocale()`, `getProxySettings()`). Рантайм-записи со стороны Node **запрещены** — `flock()` не имеет аналога в Node (нет штатного модуля, NTFS advisory-локи не проверены), и если это правило когда-нибудь нарушить, PHP-only лок молча перестанет быть достаточным, а тест конкурентности (`AppConfigStoreConcurrencyTest`, PHP↔PHP) этого не поймает.

## Установка из ZIP — базовый сервис (issue #248)

- `App\Service\Plugin\ZipPluginInstaller` (`app/src/Service/Plugin/`) — четыре шага без промежуточных состояний наружу: распаковка ZIP во временную директорию, валидация `manifest.json` тем же путём, что `InstalledPluginsRegistry::parseManifest()` (небольшое дублирование ~15 строк вместо общей абстракции на двух вызывающих — они относятся к разным жизненным циклам: одна валидирует уже установленные плагины при reconcile, другая — ещё не установленный кандидат), перенос `rename()` во `%app.plugins_dir%/<pluginId>/` и `reconcile()`.
- **Ревизия после code review**: временная директория для распаковки — **не** `sys_get_temp_dir()`, а `dirname(%app.plugins_dir%)/.plugin-install-tmp/<random>` (сосед `%app.plugins_dir%`). Причина двойная: (1) она вне `%app.plugins_dir%`, поэтому `reconcile()` не видит недособранную папку как кандидата в плагины — как и раньше; (2) она на **той же файловой системе**, что и `%app.plugins_dir%`, поэтому финальный `rename()` не падает с `EXDEV` (кросс-ФС `rename()` всегда возвращает `false` — гарантированно ловилось бы на Linux, если `/tmp` смонтирован как tmpfs, а `%app.plugins_dir%` — нет; на Windows — если `%TEMP%` и `%AppData%` на разных дисках). `sys_get_temp_dir()` этого не гарантирует. Тесты это подтверждают через `ReflectionMethod` на приватный `stagingRootDir()` (реальную кросс-ФС границу в unit-тесте детерминированно не создать без root/второго тома).
- **Ревизия после code review**: если `manifest.json` не найден в корне распакованного архива, но в нём ровно один каталог верхнего уровня и манифест лежит внутри него — `resolvePluginRoot()` спускается туда (типичный результат упаковки каталога через Проводник/`zip -r`). Любой другой случай (ноль или больше одного каталога верхнего уровня) не считается однозначным и падает как обычная ошибка «нет манифеста» из `parseManifest()`.
- **Ревизия после code review**: `assertSafeEntryNames()` явно отклоняет записи архива с `..`-сегментами пути или абсолютными путями (unix/`\\`/`C:`) до `extractTo()` — defence in depth поверх встроенной защиты `\ZipArchive` от zip-slip, т.к. эта защита не задокументирована как часть контракта метода, а установка из ZIP — путь для недоверенного пользовательского архива.
- Коллизия id проверяется двумя независимыми способами перед переносом: `InstalledPluginsRegistry::has()` (персистентный индекс) **и** `is_dir($targetDir)` (сырая проверка ФС) — вторая ловит осиротевшую директорию на диске, которую индекс не знает (например, после ручного вмешательства), и не даёт `rename()` её перезаписать.
- Откат при любой ошибке (шаги 1–4) — `catch (\Throwable)` в `install()`: временная директория удаляется всегда; целевая — только если перенос уже стартовал (флаг `$moveStarted`, выставляется непосредственно перед `rename()`), чтобы откат никогда не трогал директорию, которая могла существовать до вызова (кроме случая, когда сам перенос её и создал).
- Ошибки не сворачиваются в единый тип: `InvalidInstalledPluginException` (переиспользован из `InstalledPluginsRegistry` — тот же смысл «нет валидного manifest.json в директории») отдельно от `PluginAlreadyInstalledException` (коллизия id) отдельно от `PluginInstallException` (не открылся/не распаковался архив, не удалось перенести директорию) — вызывающий код (будущий UI-контроллер, issue поверх #248) сможет показывать разные сообщения, не разбирая один общий exception по `getMessage()`.
- Активация (прогрев кэша, issue #222) и проверки совместимости/линтинг манифеста сверх `ManifestParser::parse()` — сознательно вне объёма, сервис останавливается на «файлы на месте + индекс обновлён».
- Формат архива — стандартный `\ZipArchive` (расширение `ext-zip`, добавлено в `composer.json`), без сторонней библиотеки. **Не проверено эмпирически в этой задаче**: наличие `ext-zip` в статической сборке FrankenPHP для Windows (тот же класс проблемы, что WebP/GD выше) — песочница реализации без Windows-бинарника FrankenPHP. Проверить `frankenphp.exe php-cli -r "var_dump(class_exists('ZipArchive'));"` на реальной сборке до того, как эта установка станет доступна из UI.

## Автоматическое ИИ-ревью PR (`.github/workflows/claude-review.yml`)

На каждый PR (`opened`, `synchronize`) запускается независимое ревью через `anthropics/claude-code-action@v1`: постит замечания inline-комментариями к строкам, при отсутствии проблем — короткое подтверждение. Модель `claude-opus-4-8`. Промпт в самом workflow заточен под конвенции репозитория (DDD, запрет Yoda-style, GPLv3-шапки, `trans()` в Twig).

- **Аутентификация — через OAuth-токен подписки Max, а не API-ключ.** В workflow используется `claude_code_oauth_token: ${{ secrets.CLAUDE_CODE_OAUTH_TOKEN }}`. Ревью идёт в счёт подписки, без отдельных pay-per-token расходов на нативный API. Токен генерируется локально командой `claude setup-token` и кладётся в **Settings → Secrets and variables → Actions** репозитория как секрет `CLAUDE_CODE_OAUTH_TOKEN`. Без этого секрета шаг ревью падает. Токен долгоживущий, но не вечный — при протухании ревью начнёт падать на аутентификации, тогда перегенерировать `claude setup-token` и обновить секрет.
- **Почему не `ANTHROPIC_API_KEY`.** Нативный API-ключ — это отдельный кошелёк (console.anthropic.com, оплата за токены), не покрывается подпиской Max. Сознательно не заводим, чтобы ревью шло за счёт подписки.
- **Роль в связке с openronin.** openronin (автономный агент в этом репозитории) открывает PR → этот workflow ревьюит и постит комментарии → lane `pr_dialog` у openronin сам отвечает на замечания и вносит правки. Внутренний self-review openronin (`patch_multi`) намеренно **не** включён: он требует нативного API-ключа, а независимое ревью автора самим собой всё равно слабее внешнего гейта.
- Настройка секретов и Actions в GitHub делается вручную владельцем репозитория — у PAT агента openronin нет доступа к `.github/workflows/`.

## Адаптация под plugin-contracts v0.6.0 — Этап 4 (issue #293)

- `PluginInterface` → `ExternalIdResolutionInterface` — переименование в самом пакете контрактов (не breaking по составу методов, только по имени). `Anime::getExternalId()` и все моки в тестах обновлены на новое имя. `TagPluginServicesPass` и `SearchByPluginChain`/`FillerRegistry`/`SyncRegistry` уже тегировали и перечисляли плагины по **листовым** интерфейсам (Filler/Widget/Sync/Search) и **из манифестов** (`InstalledPluginsRegistry::all()`), а не по базовому маркеру — при ренейме их трогать не пришлось, это уже было сделано раньше (issue #278/#280/#284).
- `manifest.json` `type: local` — парсинг/валидация (включая запрет полей `features`/`locales` для этого типа) целиком живут в пакете `anime-db/plugin-contracts` (`ManifestValidator::validateFeaturesOrLocales()`), не в этом приложении. Приложение уже сравнивало `PluginType` через `===`/`!==` (`PluginLoader::translationPaths()`/`integrationPlugins()`), без exhaustive `match`, поэтому bump зависимости — единственное, что требовалось; полный жизненный цикл `local`-плагина (bundle-less `EventSubscriberInterface`, first-class встраиваемость) — отдельная предрелизная задача, не входит в #293.
- **PSR-18 клиент плагинам** — `App\Service\Plugin\Http\PluginHttpClientFactory::create()` оборачивает `Symfony\Component\HttpClient\HttpClient::create()` в `Symfony\Component\HttpClient\Psr18Client`, зарегистрирован в `services.yaml` под сервис-id `Psr\Http\Client\ClientInterface` (`factory: ['@...PluginHttpClientFactory', 'create']`). Любой плагин-сервис (включая динамически загруженные из `<installPath>/src/`, issue #282 — их DI-конфиг тоже собирается с `autowire()`/`autoconfigure()`, см. `Kernel::configureContainer()`) получает преднастроенный клиент простым тайп-хинтом на этот интерфейс — без явной регистрации в манифесте/бандле плагина. Отдельный клиент от `app.meilisearch.http_client`/`app.plugin_media.http_client` — те покрывают внутренние нужды ядра со своими таймаутами, этот — общий для произвольного плагинского кода. Прокси пока не реализован — `PluginHttpClientFactory::options()` явно задокументирован как шов для него (когда появится настройка прокси, она добавляется в опции `HttpClient::create()` там же, без изменения плагинов).
- **Каскад `default_search`** — `App\Service\Plugin\DefaultSearchPluginRegistry` (тот же `#[AutowireIterator('app.search_by_plugin', indexAttribute: 'id')]`, что и `SearchByPluginChain`) + `AppSettingsProvider::getDefaultSearchPluginId()`/`setDefaultSearchPluginId()` (новый ключ `defaultSearchPluginId` в `%AppData%/config.json`, тот же read-modify-write, что `locale`). В приложении **нет** ни готовой фичи «удалить плагин» (issue #225, отдельная нереализованная задача), ни существовавшего раньше UI выбора default_search (в v1 это был шаг мастера установки) — поэтому каскад реализован **лениво**: `getDefault()` при каждом чтении сверяет сохранённый id с реально доступными сейчас `app.search_by_plugin`-плагинами (с тем же гейтингом по `features.filler`, что и у `SearchByPluginChain`/`FillerActiveTrait` — «чистый search»-плагин без тумблера filler остаётся активным), и если сохранённый id недоступен — сам пересчитывает и персистит новый (или `null`, если поисковых плагинов не осталось). Явного хука в момент удаления плагина не требуется: DI-контейнер полностью пересобирается после любой мутации плагинов («Atomic Cache Swap», см. gotchas.md), так что первое же чтение после реального удаления (когда оно появится) увидит уже обновлённый список и самостоятельно cascade'нёт.

## PSR-17 фабрики плагину (issue #309)

Плагин уже получал `Psr\Http\Client\ClientInterface` (см. Этап 4 выше) — им можно отправить запрос. Изначальное предположение этой задачи — что плагин не может его **построить** и поэтому нужно завести новый биндинг — оказалось неверным: PSR-17 `RequestFactoryInterface`/`StreamFactoryInterface` уже были глобально доступны через автовайр-алиасы на `Http\Discovery\Psr17Factory` в `config/packages/http_discovery.yaml` (вместе с `Response`/`ServerRequest`/`UploadedFile`/`Uri` — итого шесть интерфейсов на одном инстансе), т.е. плагин уже мог собрать запрос для OAuth-обмена/refresh (тонкий базовый класс в `anime-db/plugin-contracts`#41) до этого PR.

Первая версия PR добавляла в `services.yaml` алиасы `RequestFactoryInterface`/`StreamFactoryInterface` на уже созданный сервис `Psr\Http\Client\ClientInterface`, рассчитывая дать плагину один и тот же экземпляр `Psr18Client` под всеми тремя интерфейсами. Это было ошибкой на два счёта: (1) обоснование «нельзя построить» было неверным (см. выше); (2) `services.yaml` подключается **после** `config/packages/*.yaml` (`MicroKernelTrait::configureContainer`), поэтому такие алиасы тихо перекрывали бы биндинг `http_discovery.yaml` для этих двух интерфейсов **во всём приложении**, а не только для плагина — оставляя оставшиеся четыре PSR-17 интерфейса на `Psr17Factory`. Единый инстанс для всех трёх интерфейсов не был нужен и в первую очередь: PSR-7-сообщение, собранное через `Psr17Factory`, работает с `Psr18Client::sendRequest()` независимо от того, какая фабрика его построила — это и есть смысл межпакетной совместимости PSR-7/17/18. Алиасы убраны; в `services.yaml` остаётся только биндинг `ClientInterface` из Этапа 4.

## Safe mode — восстановление после серии неудачных стартов (issue #403)

После двух подряд незакрытых стартов ядра (`native/supervisor/safe-mode.js`) приложение предлагает пользователю перезапуститься с принудительно отключёнными плагинами. На уровне PHP это реализовано минимально инвазивно: `App\Service\Plugin\InstalledPluginsRegistry::readIndex()` при `safeMode === true` сразу возвращает пустой список, не читая индексный файл (`Kernel::installedPluginsRegistry()` определяет режим по `$_SERVER['SAFE_MODE'] === '1'`, которую выставляет `native/supervisor/env.js`).

Осознанное ограничение: в safe mode пользователь видит в UI пустой список плагинов и ничем не проинформирован о том, что это временный режим восстановления, а не фактическое отсутствие/удаление плагинов. Потери данных при этом нет — `InstalledPluginsRegistry::reconcile()` пересобирает индекс сканированием `manifest.json` в каталогах плагинов, а не полагается на состояние, затронутое safe mode, — но пользователь может ошибочно решить, что плагины пропали, и переустановить их поверх существующих. Баннер «приложение запущено без плагинов» и любое другое информирование в интерфейсе сознательно оставлены вне скоупа issue #403: сам менеджер плагинов в UI на момент этой задачи ещё не реализован (issue #218), добавлять баннер было бы не к чему крепить. Это должно быть закрыто вместе с менеджером плагинов или отдельной задачей до релиза — трекать as-is, чтобы не потерять при последующей реализации UI.

## i18n нативного слоя (issue #404)

Splash-экран, меню трея и диалоги ошибок в `native/` были захардкожены по-русски (единственное исключение — `buildMigrationErrorDialog()`, локализованный ad-hoc для issue #392). Заведён собственный механизм: `native/translations/{ru,en}.json` + `native/i18n` (`t(key, locale, params)`, симфонийные плейсхолдеры `%name%`, читается синхронным `JSON.parse` без рантайм-зависимостей).

- **Каталоги — свои, не общие с `app/translations/`.** У `native/` и `app/` физически нет общих строк, поэтому общий каталог задавал бы только адрес файла, а не единый источник правды. Рассмотренные и отклонённые варианты (генерация JSON из `app/translations/messages.{locale}.yaml` на этапе сборки; общий JSON-домен `app/translations/native.{locale}.json`) — оба требуют координации двух слоёв ради нуля переиспользуемого текста.
- **Fallback-цепочка — `<locale>.json → mapOsLocaleToAppLocale(locale) → ключ`, не `locale → en → ключ`.** Функция берётся из `native/config.js` (issue #177) — список постсоветских префиксов, маппящихся на `ru`, не дублируется. Причина: языки приложения расширяются плагином-переводом (`PluginType::Translation`), а он расширяет список локалей **окна**, но не нативного слоя — `getLocale()` может штатно вернуть код, для которого `native/translations/<locale>.json` не существует.
- **Принятое ограничение: локаль без нативного каталога получает ближайший встроенный язык, не английский по умолчанию.** `kk`/`kk-KZ` (и весь `RU_PREFERRED_PREFIXES`) → `ru`; всё остальное (`uk`, `de`, ...) → `en`. Окно при этом остаётся на выбранном пользователем языке — расхождение между окном и native-слоем для таких локалей ожидаемо, не баг.
- **Контракт плагинов не расширяется под нативные переводы.** ⟳ **Пересмотрено (issue #647).** Историческая формулировка сохранена как есть: *Домёрживание переводов из каталогов установленных плагинов рассматривалось и отклонено: `native/` не должен ничего знать о `plugins.json`/бизнес-логике. Набор языков нативного слоя расширяется только релизом ядра (новый `native/translations/<locale>.json` в репозитории).* Отменена только вторая половина — набор языков нативного слоя больше не привязан к релизу ядра: `App\Service\Translation\NativeTranslationsOverlayWriter` собирает `translations/native/<locale>.json` включённых плагинов типа `translation` в сплющенный оверлей в пользовательских данных, так что языковой плагин расширяет и нативный слой, не только окно. Первая половина остаётся в силе без изменений: сам `native/` по-прежнему не читает `plugins.json` и не знает о плагинах вообще — он лишь читает свой файл оверлея, ничего не подозревая об источнике его данных. Именно поэтому файл оверлея собирает ядро, а не `native/`.
- **Принятое ограничение: смена языка в настройках без перезапуска подхватывает окно, но не меню трея.** Окно перечитывает `getLocale()` при каждом рендере (как и раньше), а `native/tray/index.js` строит `Menu.buildFromTemplate()` один раз при создании трея (`tray.create()`) и не переподписан ни на какое событие смены локали — в отличие от `PROXY_CHANGED_EVENT`/`FIREWALL_RULE_CHANGED_EVENT`, для локали такого backend-события нет. Заводить его сочтено избыточным для задачи с низким приоритетом; тред трея догоняет язык окна на следующем запуске приложения.

## Приоритет ядро/плагин в переводах — детектор дрейфа, не гейт (issue #451)

Механизм подключения плагинных каталогов переводов (`PluginLoader::translationPaths()` в `framework.translator.paths`, issue #373) существовал без сквозной проверки: юнит-тесты покрывали только сборку списка путей (`PluginLoaderTest`), не то, что путь реально доезжает до `Translator`. `tests/Unit/Translation/PluginTranslationBootTest.php` бутает `App\Kernel` целиком (единственный тест в наборе, кроме `TranslatorSmokeTest`, который это делает) на фикстуре из включённого `translation`-плагина и `integration`-плагина со своим доменом, во временных `APP_RUNTIME_DIR`/`PLUGINS_DIR`/`PLUGINS_CONFIG_PATH` — см. докблок класса про то, почему обязателен именно холодный компайл контейнера, а не переиспользование `var/cache/test`.

- **Правило, гранулярность (домен × локаль × ключ).** В домене `messages` (каталог `app/translations`) core-строка всегда выигрывает у плагина, объявившего тот же (locale, key) — но дополнительные ключи плагина в том же домене/локали применяются, и плагин может ввести локаль, которой ядро не везёт вовсе. В **собственном** домене (файлы `<plugin-id>.<locale>.yaml`, issue #373) плагин полновластен в любой локали, включая `ru`/`en`.
- **Оговорка про vendor-домены.** Гарантия «core побеждает» касается только домена `messages`. В `FrameworkExtension` порядок такой: каталоги vendor-пакетов → каталоги бандлов → плагинные `paths` → `default_path` — то есть плагин, положивший файл в чужом vendor-домене (например `security.ru.yaml`), перекроет строки этого vendor-пакета, и формально это разрешено формулировкой «в своём домене плагин полновластен», раз механизм не различает «свой» домен и произвольный чужой. Практического вреда сегодня нет (аутентификации в приложении нет), но это не гарантия, которую даёт код — только то, что он умеет.
- **Это статус, унаследованный от порядка сборки путей, не установленный отдельным кодом.** `PluginLoader::translationPaths()` добавляет плагинные каталоги, `config/packages/framework.yaml`'s `default_path` (core) дописывается `FrameworkExtension`'ом после них безусловно — Symfony резолвит коллизию (domain, locale, key) в пользу последнего добавленного ресурса. Приоритет **сознательно не взят под управление**: `PluginTranslationBootTest` — детектор дрейфа, а не источник гарантии; он падает, если поведение изменится (апгрейд Symfony способен переставить порядок), но ничего в проде это не enforce'ит. Комментарий прямо в теле теста объясняет, что делать при покраснении — не подгонять ожидание под новое поведение, а сделать приоритет явным и управляемым (например, компайл-тайм валидацией, отклоняющей плагинный каталог с ключом, уже занятым в `messages` ядром) прежде чем решать, приемлем ли новый порядок.
- **Коллизия двух плагинов на одной локали — только описана, специальной обработки нет.** Поведение детерминировано, но не документировано отдельным тестом (`PluginTranslationBootTest` его не проверяет): `InstalledPluginsRegistry::reconcile()` каждый раз перестраивает индекс `installed-plugins.php` **целиком** через `scandir()` — по возрастанию id; `translationPaths()` обходит индекс в этом же порядке, так что при совпадении (domain, locale, key) выигрывает лексикографически последний id, а недостающие ключи подтягиваются из более раннего. `AvailableLocalesProvider::all()` дубли локалей схлопывает. **Предпосылка, без которой это правило не работает:** детерминизм держится на том, что `reconcile()` пересобирает индекс целиком при каждом вызове, а не дописывает запись инкрементально. Если реконсиляцию когда-нибудь оптимизируют до «дописать запись при установке плагина» — победитель молча станет определяться порядком установки, а не алфавитом id. Машинерию отказа при активации конфликтующего плагина не строим: официальный языковой пакет один, случай гипотетический.
- **`app/tests/Unit/Service/Plugin/PluginServiceAutoRegistrationTest.php`** описывал полный бут ядра как невозможный из-за фатала холодной компиляции — тот фатал исправлен в issue #458/PR #459 (см. `.claude-docs/gotchas.md` про `class_exists()` в compiler pass'ах). Докблок поправлен, чтобы не утверждать обратное рядом с рабочим `PluginTranslationBootTest`; сам этот тест по-прежнему намеренно не переведён на полный бут — он целится в узкий срез `Kernel::configureContainer()`, а не в интеграцию перевода.

## FrankenPHP-архив — курируемый набор файлов, не единственный .exe (issue #477)

`scripts/download-bins.js` извлекал из `frankenphp-windows-x86_64.zip` ровно `frankenphp.exe`. Запись #248/#309 выше (и запись про WebP/GD в архитектуре) уже отмечала как неподтверждённое, что расширения FrankenPHP на Windows вообще доступны — эта задача показала, что дело серьёзнее: `frankenphp.exe` в этой сборке не самодостаточен, ему нужны ещё несколько DLL из того же архива, а расширения PHP на Windows — не статика, а отдельные подгружаемые DLL, которых `php.ini.template` не грузил вовсе. Собранное приложение не запускалось.

- **Состав определён эмпирически, не по документации FrankenPHP.** Архив v1.12.4 (та же версия, что уже запинена в `versions.json`, — со своего CI без прав на Windows) скачан и проверен на этой же машине: `objdump -p frankenphp.exe | grep 'DLL Name'` дал импорты `php8ts.dll`, `brotlienc.dll`, `brotlidec.dll`, `libwatcher-c.dll`, `pthreadVC3.dll` (плюс системные `KERNEL32`/`VCRUNTIME140`/`api-ms-win-crt-*`, которые ставятся ОС/VC++ Redistributable, не архивом — их извлекать не нужно). Тем же способом проверены `ext/php_intl.dll` (цепочка `icuin77.dll → icuuc77.dll → icudt77.dll`, плюс прямой `icuio77.dll`) и `ext/php_zip.dll` (зависит только от системных DLL). Список расширений взят из `composer check-platform-reqs --no-dev` в `app/`: `ctype`, `iconv`, `intl`, `json`, `xml`, `zip` — из них в архиве есть `ext/php_*.dll` только для `intl` и `zip`; `ctype`/`iconv`/`json`/`xml` в этой сборке скомпилированы в сам `php8ts.dll` статически (в архиве нет `ext/php_ctype.dll` и т. п.), поэтому `extension=` для них не пишется — `extension_dir` в `php.ini.template` указывает на `bin/frankenphp/ext/`, куда извлекаются только `php_intl.dll` и `php_zip.dll`.
- **Найдена и закрыта дыра в собственноручно проверенном списке.** `brotlienc.dll`/`brotlidec.dll` (прямые импорты `frankenphp.exe`) сами транзитивно импортируют `brotlicommon.dll`, который в списке из тела issue не значился — `objdump -p brotlienc.dll`/`brotlidec.dll` вскрыл это до того, как список был зашит в `download-bins.js`. Отсюда практический вывод, а не только формальность: на каждом бампе версии FrankenPHP список `FRANKENPHP_FILES` (`scripts/download-bins.js`) нужно перепроверять тем же `objdump`-проходом по каждому извлекаемому файлу, а не просто диффать changelog — транзитивные зависимости не видны без него.
- **Курируемый набор выбран вместо полного архива.** Реальная альтернатива — извлекать архив целиком через уже существующий `extractZipToDir()` (как для `qbittorrent-nox`), без анализа состава вообще. Отклонено: архив — это редакция Windows-дистрибутива PHP на 80 файлов (~160 МБ распакованными), из которых используются 13. Основной вес неиспользуемого — `ext/php_fileinfo.dll` (10 МБ), `ext/php_gd.dll` (10 МБ) и ещё ~25 других `ext/php_*.dll`, которые ни один компонент приложения не подключает. Курируемый набор — 13 файлов, **≈112 МБ** (из них `frankenphp.exe` 57,6 МБ, `php8ts.dll` 14,1 МБ и `icudt77.dll` — сами ICU-данные — 31,9 МБ; на компактный рантайм тут рассчитывать в принципе некуда, ICU-таблицы тянут почти весь довесок сверх самого рантайма сами по себе). Экономия к полному архиву (~160 МБ) — ~48 МБ (около трети инсталлятора), при цене — сопровождение списка `FRANKENPHP_FILES` при каждом бампе версии (см. пункт выше). Выбрано в пользу размера: `download-bins.js#extractSelectedFromZip()` проверяет полноту списка **до** извлечения (ни один файл не запишется, если хоть один из ожидаемых отсутствует в архиве) — то есть при рассинхроне со следующим релизом сборка падает явно на этапе `download-bins`, а не тихо оставляет недостающую DLL и падение уже в собранном инсталляторе, как было до этой задачи. **Дополнение (review-раунд PR #483):** `isUpToDate()` изначально проверяла только `frankenphp.exe` + маркер версии — на каталоге `bin/frankenphp/`, оставшемся от сборки старой версией скрипта (только `.exe`, тот же пин `v1.12.4`), это признавало бы набор актуальным и пропускало скачивание целиком, оставляя приложение несобранным без единого сигнала об ошибке. `isUpToDate()` теперь для бинов с `zipEntries` дополнительно проверяет наличие каждого файла из списка в `destDir`, а не только версию.
- **Не проверено в этой задаче: реальный запуск на Windows.** Песочница реализации — Linux без Wine; `objdump -p` на самих файлах архива подтверждает состав импортов формально корректно, но фактический `frankenphp.exe run` + `GET /health` + `extension_loaded('intl')`/`extension_loaded('zip')` через `php-cli` — тот же класс непроверенного, что WebP/GD и `ext-zip` в записях выше. Проверить при следующей сборке на Windows до релиза. **Дополнение (review-раунд PR #483):** после добавления SQLite в этот же курируемый набор одного `GET /health` уже недостаточно для приёмки — он не открывает соединение с БД. Нужна проверка, которая реально обращается к SQLite (например, страница со списком) — иначе отсутствие `pdo_sqlite`/`libsqlite3.dll` пройдёт мимо healthcheck и проявится только на первом реальном запросе. Туда же на ту же Windows-приёмку: убедиться, что opcache работает (в курируемом наборе нет `ext/php_opcache.dll` — предполагается, что он вкомпилен в `php8ts.dll`, но это не проверено объективно, только по аналогии с `ctype`/`iconv`/`json`/`xml`), и решить, что делать с секцией `[apcu]` в `php.ini.template` — APCu в поставке не идёт, а адаптер в `cache.yaml` закомментирован, так что несовпадение конфигурации сейчас не имеет эффекта, но стоит явно решить, оставлять ли задел или убрать.
- **Список расширений выведен из недообъявленных requirements — не только из `check-platform-reqs`.** Первая версия этой задачи брала список исключительно из `composer check-platform-reqs --no-dev` (`ctype`, `iconv`, `intl`, `json`, `xml`, `zip`) и на этом основании не довезла `pdo_sqlite` и `openssl` — независимое ревью (peter-gribanov, с собственной перепроверкой `objdump`) показало, что оба расширения приложению нужны, просто не объявлены в `app/composer.json`: `pdo_sqlite` — обе Doctrine-connection (`app/config/packages/doctrine.yaml`, `default`/`queue`) заданы с `driver: pdo_sqlite`, `native/supervisor/env.js` передаёт `DATABASE_URL`/`QUEUE_DATABASE_URL` как `sqlite:///...`; `openssl` — `symfony/http-client` в `Service/Market/PluginRegistryFetcher.php`/`MarketAssetDownloader.php` без `ext-curl` падает на `NativeHttpClient`, а HTTPS через потоки PHP на Windows требует openssl. И `ext-pdo_sqlite`, и `ext-openssl` добавлены в `app/composer.json#require` — так `check-platform-reqs` перестаёт быть источником ложноотрицательного вывода, а `platform-check: true` (issue #467) превращает недостающую DLL в явный фатал при старте, а не в тихий отказ маркета или крэш на первом запросе к БД (симптом в исходном варианте смещался мимо healthcheck — `frankenphp.exe` стартовал бы нормально, `pdo_sqlite`/`openssl` подключаются лениво). Перепроверено на том же архиве v1.12.4 (SHA-256 совпал с пином): `objdump -p ext/php_pdo_sqlite.dll` → `php8ts.dll`, `libsqlite3.dll`; `objdump -p ext/php_openssl.dll` → `php8ts.dll`, `libcrypto-3-x64.dll`, `libssl-3-x64.dll` (плюс системные `CRYPT32`/`WS2_32`, ставятся ОС); `libssl-3-x64.dll` транзитивно тянет `libcrypto-3-x64.dll`. Все пять новых файлов (`ext/php_pdo_sqlite.dll`, `ext/php_openssl.dll`, `libsqlite3.dll`, `libssl-3-x64.dll`, `libcrypto-3-x64.dll`) добавлены в `FRANKENPHP_FILES`, курируемый набор вырос до 18 файлов, **≈122 МБ** (+~10 МБ, основной вес — `libcrypto-3-x64.dll`, 7 МБ). `extension=pdo_sqlite`/`extension=openssl` добавлены в `php.ini.template`. Альтернатива для HTTPS — везти `php_curl.dll` вместо `openssl` — отклонена: у него длиннее цепочка зависимостей (`libssh2.dll`, `nghttp2.dll`, `brotlidec.dll`, `IPHLPAPI.dll`, `Secur32.dll` вдобавок к `libssl-3-x64.dll`), а прямой необходимости в cURL (а не в HTTPS как таковом) в коде нет.
- **Полифил не отменяет необходимость расширения — и это третий случай подряд с одной причиной (issue #490).** `ext-mbstring` не попал в курируемый набор по той же логике, что раньше пропустила `pdo_sqlite` и `openssl`: вывод состава из `composer check-platform-reqs --no-dev` систематически промахивается мимо требований, закрытых полифилом или не объявленных в `require`, — оба случая для `check-platform-reqs` неразличимы от «не нужно вовсе». `symfony/polyfill-mbstring` тянется транзитивно девятью установленными пакетами (`symfony/console`, `http-foundation`, `framework-bundle`, `translation`, `string`, `var-dumper`, `filesystem`, `doctrine-bridge`, `twig/twig`), и приложение само вызывает `mb_strtolower()` напрямую (`App\Entity\NameNormalizer` — Unicode case folding для сопоставления названий при поиске/линковке, `App\Service\Download\DownloadFolderJail` — сверка границ каталога загрузок), но требование нигде не объявлено, а полифил принимается за расширение молча. Риск не в падении: полифил реализует простое посимвольное преобразование по таблицам Unicode (`Resources/unidata/lowerCase.php`, `upperCase.php`, `caseFolding.php`) и на подавляющем большинстве входов совпадает с расширением — расходится он на контекстно-зависимых правилах, проверенный пример: греческая финальная сигма (`mb_strtolower('ΣΊΣΥΦΟΣ')` → `σίσυφος` у расширения против `σίσυφοσ` у полифила). Для `NameNormalizer` это по-прежнему означает тихо другой результат сравнения, просто на более узком классе входов, — класс багов, который обнаруживается нескоро (и уже мешал: `PHPUnit` вообще отказывался стартовать под боевым `frankenphp.exe`, требуя `mbstring` в списке из семи расширений, — единственного недостающего из этого списка). При загруженном расширении полифил не активирует свои функции сам (Symfony делает это через `function_exists()`-проверку в самих polyfill-файлах), так что `symfony/polyfill-mbstring` из зависимостей не убирается — он остаётся корректным запасным путём для окружений без расширения, просто не для этого приложения, которое везёт собственный PHP. Закрыто как и предыдущие два случая: `ext-mbstring` добавлен в `app/composer.json#require` явно, `platform-check: true` (issue #467) теперь ловит его отсутствие фаталом при старте вместо тихой подмены на эмуляцию. `ext/php_mbstring.dll` добавлен в `FRANKENPHP_FILES`; `objdump -p` на архиве v1.12.4 (SHA-256 совпал с пином) показал импорты только `php8ts.dll` и системные (`VCRUNTIME140`, `api-ms-win-crt-*`, `KERNEL32`) — как у `php_zip.dll`, транзитивных сторонних DLL нет, новых файлов в набор добавлять не пришлось. `extension=mbstring` добавлен и в `php.ini.template` (свежие установки), и в `REQUIRED_EXTENSION_DIRECTIVES` (`native/php-ini.js`) — иначе апгрейд существующей установки не получил бы новую директиву в уже сгенерированный `php.ini` в AppData, тот же паттерн, что уже описан ниже для `ensurePhpIni()`. **Три случая одной и той же причины** (недообъявленное или полифилленное требование не видно `check-platform-reqs`): `pdo_sqlite` — обе Doctrine-connection используют его, но объявлен не был; `openssl` — нужен `symfony/http-client` для HTTPS на Windows, тоже не был объявлен; `mbstring` — объявлен полифилом, что для `check-platform-reqs` то же самое, что не объявлен вовсе. Вывод: список `FRANKENPHP_FILES`/`php.ini.template` нельзя больше выводить из одного прогона `check-platform-reqs` — только из явного `require` в `app/composer.json`, сверенного с реальным использованием в коде.
- **`ensurePhpIni()` не обновляла ini, сгенерированный старым шаблоном.** Функция выходила раньше по факту существования файла (`if (fs.existsSync(iniPath)) return`) — у любой установки, которая хоть раз запускалась до этой задачи (или до следующего бампа набора расширений), `php.ini` в AppData оставался без новых `extension=`-строк после обновления приложения: чистая установка работала, апгрейд — нет. Переписывать файл целиком нельзя — он лежит в AppData и по замыслу может быть отредактирован пользователем вручную. Решение — `appendMissingIniDirectives()` в `native/supervisor/frankenphp.js`: при существующем ini дописывает в конец файла только те `extension=`-директивы из `REQUIRED_EXTENSION_DIRECTIVES`, которых там ещё нет (проверка по regex, только присутствие/отсутствие), не трогая остальное содержимое. **Дополнение (review-раунд PR #483):** сравнение "директива присутствует / отсутствует" самодостаточно только для `extension=` — они не зависят от того, куда установлено приложение. Для `extension_dir` это не так: его значение — путь установки, и переустановка в другой каталог (NSIS это позволяет) оставляет в AppData старый ini со старым, уже неверным путём; проверка одного присутствия признала бы такую строку валидной. Поэтому `extension_dir` — отдельный случай: значение существующей строки сравнивается с актуально вычисленным путём и переписывается на месте при расхождении, а не только дописывается при отсутствии.

## ImageNormalizer — санитизация изображений через переэнкод в WebP (issue #503)

`App\Service\Media\ImageNormalizer::normalize(string $bytes): ?string` — чистый сервис без сети/файловой системы/Doctrine, декодирует произвольные байты изображения и переэнкодирует их в WebP, без passthrough ни для одного формата (включая уже пришедший WebP). Обоснование самого алгоритма (площадь → отказ до декода, сторона → масштабирование после декода, критерий успеха энкода по сигнатуре, а не по возврату `imagewebp()`) подробно описано в докблоках констант `MAX_AREA_PIXELS`/`MAX_SIDE_PIXELS`/`WEBP_QUALITY` самого класса — здесь фиксируются только решения, не следующие напрямую из issue.

- **`imagedestroy()` убран из кода целиком, а не только из вновь написанного.** Первая версия сервиса и тестов вызывала `imagedestroy()` после каждого `imagecreate*()`/`imagescale()` по инерции из PHP < 8.0. На PHP 8.5 (систем­ный интерпретатор в этой песочнице и, вероятно, на будущем CI-раннере) это уже не no-op, а `E_DEPRECATED`: функция ничего не делает начиная с PHP 8.0 (`GdImage` — обычный объект, собирается GC), и с 8.5 об этом предупреждает. Все вызовы удалены из `ImageNormalizer` и из тестовых хелперов — не подавлены, а именно убраны как мёртвый код.
- **Фикстура анимированного WebP — единственный бинарный файл, закоммиченный в `tests/Fixtures/`.** Все остальные фикстуры (JPEG, статический WebP, палитровый PNG с прозрачностью, «PNG-бомба» с фейковым IHDR, склеенный вручную анимированный GIF, полиглот) генерируются в самом тесте через `ext-gd`, который гарантированно есть в любом окружении, где вообще имеет смысл гонять этот тест. Для анимированного WebP такого пути нет: в песочнице нет `Imagick` и нет `cwebp`/`img2webp` из коробки (пакет `webp` пришлось ставить через `apt-get` отдельно, только чтобы сгенерировать фикстуру один раз) — рассчитывать, что эти инструменты есть на будущем CI-раннере, нельзя. Поэтому готовый результат (`tests/Fixtures/Media/animated.webp`, 140 байт: `img2webp` из двух кадров 4×4) закоммичен как обычный бинарный фикстур-файл; сам тест инструментов для генерации WebP не требует, только `ext-gd` на чтение.
- **Поведение GD на анимированном WebP — установленный факт, а не подтверждённая заранее гипотеза из issue.** Эмпирически проверено (GD 2.3.3, `WebP Support` = true): `getimagesizefromstring()` на анимированном WebP отрабатывает нормально (репортит размеры первого кадра), а `imagecreatefromstring()` возвращает `false` — decode всего файла проваливается целиком, ни один кадр не извлекается. Значит `normalize()` для такого входа детерминированно возвращает `null`, а не «WebP от первого кадра», как для GIF. Зафиксировано и в докблоке класса, и в `ImageNormalizerTest::testNormalizeRejectsAnAnimatedWebpInput()`, с явной оговоркой, что это поведение конкретной сборки libgd/libwebp, а не гарантия протокола.
- **Тест альфы после масштабирования проверяет закрашенный блок, не одиночный пиксель.** Первая версия фикстуры «палитровый PNG с прозрачностью + сторона >16383» ставила один непрозрачный пиксель в правом верхнем углу — после `imagescale()` с даунскейлом ×0.82 пиксель терялся: ресемплинг-фильтр GD (по умолчанию бикубический) размывает одиночную точку в окружающую прозрачность, при этом сам альфа-канал транспортируется корректно (это подтверждено на самом сервисе, не на тесте). Фикстура переписана на закрашенный прямоугольник (правая половина изображения непрозрачна) — тест перестал зависеть от точного алгоритма ресемплинга и проверяет именно то, что требуется по критериям приёмки (альфа не потеряна после вписывания в предел), а не побочный эффект выбранного GD интерполятора.
- **Не сделано в этой задаче: `.github/workflows/runtime-parity.yml` не тронут.** Файл — в списке защищённых путей для автономного прогона. Тест помечен `#[Group('runtime-parity')]` и уже участвует в основном Linux-прогоне (`vendor/bin/phpunit` без опций гоняет всё, включая эту группу — см. комментарий в `phpunit.xml.dist`), но конкретно Windows-джоба (`runtime-parity.yml`) фильтрует срабатывание по явному списку путей и не запустится на этот PR, пока кто-то не добавит туда `app/tests/Unit/Service/Media/ImageNormalizerTest.php`, `app/tests/Fixtures/Media/animated.webp` и `app/src/Service/Media/ImageNormalizer.php`. Промах не даёт ложно-зелёного — тест всё равно обязателен и зелен на Linux — но Windows-специфичный (боевой `frankenphp.exe`, GD bundled 2.1.0-compatible) сигнал для этого сервиса появится только после того, как список путей обновят отдельно. **Снято:** список путей дополнен отдельным PR мейнтейнера — в фильтр добавлены `app/src/Service/Media/**`, `app/tests/Unit/Service/Media/ImageNormalizerTest.php` и `app/tests/Fixtures/Media/animated.webp`. Фикстура попала в список отдельной строкой, потому что она единственная бинарная и закоммиченная, а не генерируемая тестом: её подмена меняет исход прогона так же, как правка кода. Важная деталь про порядок: у джобы в триггерах только `pull_request`, пуша в `master` нет — поэтому сигнал по уже смёрженному коду появляется не задним числом, а на первом же следующем PR, попадающем в фильтр (правка самого `runtime-parity.yml` в него входит, так что первым таким PR стал этот).

## HttpPluginMediaDownloader — врезка ImageNormalizer в путь скачивания (issue #504)

`App\Service\Plugin\Filler\HttpPluginMediaDownloader::download()` вызывает `ImageNormalizer::normalize()` (issue #503) на теле ответа сразу после `fetch()` и до записи на диск. `null` от нормализатора — файл не пишется, `download()` возвращает `null`, отказ логируется (`LoggerInterface::warning()`) с URL и причиной.

- **Имя файла — всегда `sha1($url).'.webp'`, без определения расширения по URL.** До этой задачи расширение бралось из URL через `guessExtension()` (константа `ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif']`, дефолт `.jpg` для нераспознанного) — источник доверял тому, что написано в URL, хотя по этому же расширению `native/protocols/app-media.js` затем назначает Content-Type при рендере. Раз каждый файл на диске теперь безусловно WebP (нормализатор не имеет passthrough — см. запись про issue #503 выше), расширение стало не источником данных, а константой; `guessExtension()` и `ALLOWED_EXTENSIONS` удалены вместе со всеми точками использования. Ранняя проверка `is_file()` осталась одна — кандидат на детерминированном пути ровно один, дублирующих проверок по разным расширениям больше нет.
- **Запись на диск — временный файл в целевом каталоге + `rename()`, не прямой `file_put_contents()` по целевому пути.** `file_put_contents()` не атомарен: обрыв процесса на записи оставил бы усечённый `.webp`, который единственная ранняя проверка `is_file()` приняла бы за готовый файл — а `Cache-Control: public, max-age=31536000, immutable` из `app-media.js` (issue #68, см. запись выше) зацементировал бы битую картинку в Chromium навсегда. `tempnam($targetDir, 'tmp-')` создаёт временный файл в том же каталоге, что и целевой путь, `file_put_contents()` пишет в него, затем `rename()` переносит на целевое имя; при неудаче на любом шаге временный файл удаляется явно (`@unlink`), под целевым именем ничего не остаётся.
- **Каталог временного файла проверяется явно — сам `tempnam()` его не гарантирует.** Если `$targetDir` недоступен для записи, `tempnam()` не падает, а молча создаёт файл в системном временном каталоге. Тогда `rename()` перестаёт быть переименованием внутри одной файловой системы и становится кросс-девайсным переносом — не атомарным, а на Linux и вовсе невозможным. Поэтому после `tempnam()` сверяется, что файл действительно лёг в целевой каталог, и при расхождении он удаляется, а запись считается неуспешной. Сравнение идёт через `realpath()`, а не по строкам: `$targetDir` собирается через прямой слэш (`rtrim($mediaDir, '/\\').'/'.$animeId`), а `tempnam()` возвращает платформенный путь — на Windows один и тот же каталог записывался бы по-разному, и строковое сравнение отбраковывало бы нормальные файлы на каждой записи. Благодаря этой проверке «временный файл всегда лежит рядом с целевым» — инвариант метода, а не наиболее вероятный исход.
- **Логируются все три причины отказа, разными сообщениями.** Отказ нормализации, неудачный `mkdir()` целевого каталога и неудачная запись на диск возвращают `null` одинаково, но пишут в лог разный текст (плюс URL). Без этого полный диск, проблема с правами и «источник отдал не картинку» выглядели бы снаружи одинаково: обложки нет, в логах пусто.
- **MIME-whitelist в `native/protocols/app-media.js` (запись про issue #68 выше) этой задачей не сужается и не трогается** — это следующая, отдельная задача, которая по условиям issue #504 идёт строго после текущей. Запись про этот whitelist остаётся в силе как есть; она описывает независимый набор в нативном слое, а не константу `ALLOWED_EXTENSIONS`, удалённую этой задачей.

## Точечное заполнение обложки и кадров + три исхода вместо bool (issue #507)

`FillableFieldsPresenter::FIELDS` и regex маршрута `anime_fill_field` пополнены `cover`/`images` — весь путь скачивания/санитизации изображений (issue #503/#504), до этой задачи не используемый ни одним сценарием кроме сидера демо-данных, стал достижим из UI.

- **`PluginAnimeDataMerger::apply()` — `void` → `list<string>` неприменённых полей, а не отдельный enum-результат на каждое поле.** Различать три исхода (применено / источник пуст / источник дал изображение, но оно отклонено) нужно только на уровне `FieldFillerService::fill()`, вызываемого с одним полем за раз; `apply()` же остаётся многополевым методом, вызываемым и из `BulkFillerService` с целым набором полей. Вместо того чтобы вводить статус на каждое поле (что потребовало бы менять сигнатуру и семантику для всех 12 полей ради двух), `apply()` возвращает только список полей, чьи непустые входные данные не удалось применить, — сегодня это может быть только `cover`/`images`, остальные поля никогда не попадают в список, и `BulkFillerService`, игнорирующий возврат, остаётся рабочим без изменений (совместимый по контракту вызов).
- **Критерий «применено» для `images` — успешный download хотя бы одного URL, а не появление нового файла в галерее.** `applyImages()` дедуплицирует по имени файла (issue #231) — повторный клик по уже заполненной галерее закономерно скачает те же файлы и не добавит ни одного нового элемента. Если бы критерием было «добавлен новый кадр», это привело бы к ложному `anime_detail.error_fill_image_rejected` на полностью успешном повторном скачивании. Критерий «хотя бы один URL успешно скачался» отличает настоящий отказ (сеть/нормализация) от дедупликации, которая отказом не является.
- **Не-персистнутый `Anime` в `applyCover()`/`applyImages()` — не отказ, а пропуск.** Оба метода при `$anime->id === null` возвращают `true` (не «неприменено»), потому что скачивание тогда даже не пытается стартовать: путь `%AppData%/media/{id}/` не существует без id. `BulkFillerService` и так не передаёт `cover`/`images` в `$fields` для этого случая (см. запись issue #231 выше), так что на практике это условие в новой точечной сцене не встречается вовсе — `FieldFillerService::fill()` работает только с уже персистнутыми Anime.
- **Обложка и галерея вынесены в `anime/_media.html.twig`/`anime/_gallery.html.twig` со стабильными `id="anime-media-{id}"`/`id="anime-gallery-{id}"` и `hx-swap-oob="outerHTML"` на корневом элементе.** Первое применение `hx-swap-oob` в проекте. Атрибут безвреден на обычном полном рендере `show.html.twig` — HTMX обрабатывает oob-свопы только при разборе ответа на свой собственный ajax-запрос, на исходной загрузке страницы никакого свопа не происходит и атрибут остаётся инертной разметкой. Это позволило не заводить два варианта партиала (обычный/oob) и не передавать в контроллер флаг «это oob-рендер».
- **Секция галереи убрана из-под `{% if anime.images is not empty %}` — контейнер существует всегда, «нет кадров» стало состоянием внутри него.** До этой задачи пустая галерея отсутствовала в DOM целиком; oob-своп может заменить только уже существующий по `id` элемент, так что первое же успешное заполнение кадров при пустой галерее не имело бы куда встать. `anime-detail__gallery-empty` — минимальный текстовый плейсхолдер, тот же паттерн, что `anime-detail__cover--placeholder` уже использовал для обложки.
- **Кнопка в галерее не имеет семантики замены — только добавление, вслед за `applyImages()`.** В приложении нет удаления отдельных кадров, поэтому вторая семантика (замена) означала бы безвозвратную потерю без возможности отмены; кнопка сознательно не проектировалась как «заменить галерею», хотя кнопка обложки как раз перезаписывает (`applyCover()`) — у двух кнопок в этой задаче разная семантика, наследуемая от уже существующего `PluginAnimeDataMerger`, а не введённая заново.
- **Список плагинов для cover/images — тот же `FillableFieldsPresenter::build()`, без отдельного среза.** Метод и так строился как единая точка правды для «какие плагины поддерживают какое поле» (issue #234); добавление `cover`/`images` в `FillableFieldsPresenter::FIELDS` было единственным изменением, нужным партиалам обложки/галереи — они импортируют существующий макрос `fill_button` из `anime/_fill_fields.html.twig`, а не дублируют разметку кнопки.
- **`PluginAnimeDataMerger` не тронут** — он уже корректно обрабатывает `null` от `download()`, отдельного изменения контракта в эту сторону задача не требовала.

## Массовое заполнение обложек/кадров через отдельную очередь `media` (issue #508)

`BulkFillerService::build()` (issue #227) больше не исключает `cover`/`images` из bulk-заполнения — оба массовых сценария (`ScanStorageService`, `PullSyncService`) идут через этот метод. Раньше исключение объяснялось отсутствием id аниме на момент резолва; к моменту этой задачи id уже был доступен (см. запись issue #297 про изоляцию `flush()`), но код не был обновлён — техническая причина отпала, осталось скоуповое решение без обоснования.

- **Скачивание вынесено в очередь, а не включено в синхронный `PluginAnimeDataMerger::apply()`.** Простое удаление `cover`/`images` из `array_diff()` было бы недостаточно: `apply()` для этих полей вызывает `HttpPluginMediaDownloader::download()` напрямую (issue #504) — синхронный HTTP-запрос на каждый URL заблокировал бы весь bulk-сценарий (скан хранилища на сотни тайтлов) на время скачивания всех обложек и кадров. Поэтому `build()` по-прежнему исключает `cover`/`images` из набора, передаваемого в `$this->merger->apply()` — `PluginAnimeDataMerger` в этой задаче не меняется, точечное заполнение (issue #507) продолжает использовать его синхронный путь как есть — но использует `getFillableFields()`-принадлежность этих двух полей (то же исключённое множество) как сигнал «этот плагин заявляет cover/images» для отдельного шага: `BulkFillerService::dispatchMediaDownloads()` читает `$data->cover`/`$data->images` напрямую и диспатчит по одному `App\Message\DownloadAnimeMediaMessage` на URL через `MessageBusInterface`. Диспатч идёт уже после того `$this->entityManager->flush()`, который персистит саму строку `Anime` и даёт ей id (см. п. про изоляцию `flush()` выше) — `build()` не завершается собственным финальным `flush()`, а `$anime->id` к моменту диспатча гарантированно есть, и хендлер в другом процессе эту строку уже увидит.
- **Одно сообщение — один URL, не одно сообщение на аниме.** Галерея из десятка кадров в одном сообщении заняла бы consumer на время скачивания всей пачки — ровно та задержка, которую должна снимать низкоприоритетная очередь. `DownloadAnimeMediaMessage(int $animeId, string $url, bool $isCover)` намеренно самодостаточно: несёт URL, а не только id, чтобы хендлер не обращался к плагину повторно — источник (`PluginAnimeData`) уже был получен один раз при резолве в `build()`, и повторный вызов плагина дал бы лишний round-trip и, возможно, другой результат (внешний источник меняется между сканом и обработкой очереди).
- **Новый транспорт `media`, а не второй процесс-потребитель.** `config/packages/messenger.yaml` заводит `media` на том же DSN, что и `async` (`%env(MESSENGER_TRANSPORT_DSN)%`), с `options.queue_name: media` — тот же Doctrine-транспорт и та же таблица `messenger_messages` в `data/queue.db` (issue #97), просто с другим значением колонки `queue_name`; `messenger:setup-transports` идемпотентен и создаёт обе логические очереди в одной таблице без миграций. Приоритет — не свойство транспорта, а порядок аргументов `messenger:consume`: `native/supervisor/messenger-consumer.js` теперь спавнит `messenger:consume async media` — worker берёт сообщение из `media` только когда `async` полностью пуст, поэтому `IndexAnimeMessage`/`PushSyncMessage` никогда не ждут за пачкой картинок. Второй долгоживущий PHP-процесс под `media` сознательно не заводится: это ещё один процесс в памяти пользователя рядом с сервером приложения, Meilisearch и Electron, а приоритетная очередь через порядок транспортов решает задачу без него. Худший случай задержки приоритетного сообщения — одна уже начатая загрузка, ограниченная `max_duration: 15` у `app.plugin_media.http_client` (`config/services.yaml`).
- **`failure_transport` не настраивается и в этой задаче не трогается** (см. запись issue #97 выше — решение принято раньше и на уровне всего приложения, не точечно). Это осознанно достаточно: `HttpPluginMediaDownloader::download()` не бросает исключений вообще — любой отказ (недоступный хост, SSRF-блокировка, непройденная нормализация) уже возвращает `null` и логируется предупреждением внутри самого downloader'а. `DownloadAnimeMediaMessageHandler` трактует `null` как «нечего применять», логирует и завершается без исключения — с точки зрения Messenger сообщение обработано успешно, ретраев не будет и не должно быть. Аниме, которое успели удалить между диспатчем сообщения и его обработкой, трактуется так же: это легальная гонка, а не отказ, поэтому хендлер молча завершается (`return`) без исключения — бросать здесь означало бы сжечь все ретраи и засорить логи на штатном исходе. Хендлер в этой задаче не бросает исключений вообще.
- **Дедупликация кадров в хендлере — по имени файла, вручную, тем же способом, что `PluginAnimeDataMerger::applyImages()`.** `Anime::addImage()` сам не дедуплицирует. Имя файла детерминировано (`sha1($url).'.webp'`, issue #504), поэтому повторная обработка сообщения с тем же URL (redelivery или просто дублирующийся URL в `PluginAnimeData::$images`) должна быть no-op для галереи, а не создавать вторую запись — хендлер проверяет существующие `source` галереи перед `addImage()`.
- **Известное ограничение: негативного кеша на отклонённые URL нет, и это не откладывается, а осознанно не решается.** URL, который не удалось скачать или нормализовать, ничего не оставляет на диске — ранняя проверка `is_file()` в `HttpPluginMediaDownloader::download()` не может отличить «ещё не пробовали» от «уже отклонили», поэтому такой URL будет предпринят заново при следующем полном скане хранилища. Отдельное хранилище состояния «этот URL уже отклонён» решило бы это, но потребовало бы и политики инвалидации: нормализатор (issue #503) со временем меняется, и статус «однажды отклонён» без инвалидации сделал бы отказ постоянным даже после того, как нормализатор научился бы обрабатывать этот случай. Цена — лишний исходящий трафик при повторном полном скане, а это редкая операция; отдельная задача на негативный кеш не заводится этим решением.

## `auto_mapping: false` + явный блок `mappings` для Doctrine ORM (issue #533)

`app/config/packages/doctrine.yaml` до этой задачи держал только `naming_strategy` и `auto_mapping: true` в секции `orm`, без блока `mappings`. `auto_mapping: true` регистрирует маппинги только для зарегистрированных Symfony-бандлов (`DoctrineExtension::loadOrmObjectManagerMappingInformation()`) — пространство `App\Entity` бандлом не является, поэтому цепочка маппингов была пуста: `doctrine:mapping:info` сообщал «You do not have any mapped Doctrine ORM entities», а любой HTTP-запрос, обращающийся к репозиторию сущностей, падал 500 (`MappingException`). Дыра не ловилась ни миграциями (голый SQL, метаданные не нужны), ни `/health` (`HealthController` делает сырой DBAL-запрос, ORM не касается), ни существующими тестами (репозиторные тесты строят собственный `EntityManager` напрямую по `src/Entity`, остальные `KernelTestCase` ORM через контейнер не проверяли).

- **Явный `mappings.App` (`type: attribute`, `is_bundle: false`, `dir: '%kernel.project_dir%/src/Entity'`, `prefix: 'App\Entity'`, `alias: App`) вместо включения `auto_mapping` обратно.** `src/Kernel.php` (`yield from $this->pluginLoader()->integrationBundles()`) позволяет плагину зарегистрировать свой бандл — при `auto_mapping: true` каталог `Entity/` такого бандла подхватился бы автоматически. Но `config/packages/doctrine_migrations.yaml` задаёт единственный путь `'DoctrineMigrations': '%kernel.project_dir%/migrations'` — механизма привезти свою миграцию у плагина нет, создать таблицу под свою сущность нечем. Автомаппинг сущности плагина дал бы маппинг без таблицы — отложенную поломку вместо явной. Плагинным данным спроектирован отдельный дом (`src/Service/Plugin/PluginDataStore.php`); плагины не везут сущности, и если такая потребность появится, она открывается отдельным решением вместе с механизмом миграций плагина (YAGNI — не заводится этой задачей).
- **Регрессия закрыта тестом на проводку, а не на содержимое YAML.** `tests/Unit/Doctrine/EntityMappingWiringTest.php` — `KernelTestCase`, который поднимает контейнер, берёт `EntityManagerInterface` и проверяет, что `getMetadataFactory()->getAllMetadata()` не пуст и содержит `App\Entity\Storage`. Существующие репозиторные тесты (`StorageRepositoryTest` и аналогичные) строят `EntityManager` напрямую по пути `src/Entity`, минуя контейнер, — они самодостаточны и быстры, но не проверяют, что сама конфигурация ORM в контейнере рабочая; этот пробел новый тест и закрывает, не заменяя и не переписывая существующие.

## PHP тулинга и `require.php` — версия поставки, а не минимально возможная (issue #536)

До этой задачи все четыре workflow поднимали PHP 8.4, а `app/composer.json` объявлял `"php": ">=8.4"` — при том что запиненный в `scripts/versions.json` FrankenPHP несёт 8.5.8. Тесты, PHPStan, cs-check и сборка релиза шли на минорную версию младше интерпретатора, который реально исполняет приложение у пользователя.

- **`php-version: '8.5'` во всех workflow с PHP сразу** (`ci.yml`, `build.yml`, `runtime-parity.yml`, `i18n-coverage.yml`, `frontend-smoke.yml`, `plugin-contracts-check.yml`, `plugin-contracts-drift.yml`). Один отставший файл тихо продолжал бы проверять старую версию, поэтому они меняются вместе; развёрнутое обоснование лежит в `ci.yml`, остальные на него ссылаются. Совпадение здесь по **минору**, не по патчу: `setup-php` всегда ставит свежий патч запрошенного минора (на прогоне #536 это был 8.5.9 против поставочного 8.5.8), а пин точного патча пришлось бы двигать вслед за каждым релизом PHP и он всё равно расходился бы с моментом бампа FrankenPHP.
- **`require.php` поднят до `>=8.5`.** Констрейнт объявляет версию, на которой приложение работает, а не самую старую, на которой оно ещё запустилось бы: у проекта ровно один рантайм — тот, что лежит в поставке. Побочно это делает границу машинно-проверяемой: `config.platform` не задан, значит composer сверяется с реальным PHP машины, а `platform-check: true` (issue #467) генерирует `vendor/composer/platform_check.php` с `PHP_VERSION_ID >= 80500` — на 8.4 тулинг теперь падает на автозагрузчике с внятным сообщением, а не проходит и расходится с прод-поведением. `composer.lock` при этом обновлён только `--lock`: изменились `content-hash` и `platform.php`, ни один пакет не переустановлен.
- **`phpVersion` в слепке `scripts/frankenphp-runtime.json` и в сверке `check-runtime-parity.js`.** Это единственная из версий, которую никто не пинит руками: `versions.json` фиксирует релиз FrankenPHP, а какой PHP внутри — решает апстрим. Без этого поля бамп FrankenPHP, переносящий приложение на другой минор PHP, прошёл бы сверку молча, хотя механизм заведён ровно чтобы такое падало в CI.

## Гейт релиза — запуск собранного приложения чёрным ящиком (issue #535)

До этой задачи приложение не запускалось ни одной автоматической проверкой. `ci.yml` гоняет логику, `runtime-parity.yml` — интерпретатор и его расширения, но ни FrankenPHP как сервер, ни Electron не стартовали нигде. Цена пробела измерена: `app/Caddyfile` пролежал со дня первого коммита в состоянии, при котором Caddy его не загружает, а Doctrine — без единого замапленного пространства сущностей (issues #532, #533). Ни одну из двух поломок не поймали ни ~1400 PHP-тестов, ни ~500 JS, ни PHPStan, ни cs-check, ни Windows-джоба.

- **Проверяется `dist/win-unpacked`, а не рабочее дерево.** Это тот самый состав, который упаковывается в инсталлер, поэтому заодно проверяются globs `build.files` — единственное место, где их полнота вообще подтверждается.
- **Шаг живёт в `build.yml` между сборкой и публикацией, а не отдельной джобой на каждый PR.** Цель, сформулированная автором, — «поднять приложение хотя бы раз за релиз и убедиться, что оно работает именно в том составе, в котором мы поставляем его пользователям». Отдельная джоба на PR платила бы Windows-минутами (×2) за каждую правку; здесь всё уже скачано и собрано, так что гейт стоит секунды поверх существующей джобы. Красный гейт означает отсутствие релиза, а не выпущенный и сломанный.
- **Скрипт чёрно-ящичный: `scripts/release-smoke.js` ничего не требует из `native/`.** Ни env не собирает, ни портов не выбирает — их выбирает сам супервизор. Причина не в чистоте, а в том, что `native/supervisor/env.js` и `native/paths.js` завязаны на Electron (`require('electron')`), и любая попытка позвать их из обычного Node кончилась бы копией сборки env в скрипте — ровно тем, что докблок `env.js` запрещает («новый спавн PHP не должен собирать env самостоятельно»). Чёрный ящик снимает вопрос: измеряется то, что делает поставка, а не его реконструкция.
- **Изоляция и наблюдаемость — через `--user-data-dir`.** Electron уважает этот ключ для `app.getPath('userData')`, от которого `native/paths.js` считает все рантайм-пути. Прогон не трогает существующую установку, а PID-файлы супервизора (`var/pids/frankenphp.pid`) лежат там, где скрипт их найдёт, без угадывания имени продукта.
- **Порты берутся у ОС по PID, а не задаются заранее.** Приложение выбирает свободные порты само и нигде их не публикует; `Get-NetTCPConnection -OwningProcess` отвечает на этот вопрос, не меняя поведение приложения.
- **Оба сокета проверяются одинаково, а не различаются.** После issue #532 WS-блок отдаёт тот же root, что и APP-блок, — снаружи они неотличимы. Утверждение «оба отвечают» строже, чем «один из них», и попутно фиксирует, что сокетов ровно два.
- **`/health` в наборе проверок недостаточен, и это зафиксировано тестом.** `HealthController` делает сырой DBAL-запрос и ORM не касается — он отвечал 200 весь период, пока каждая страница каталога была 500. Поэтому в наборе `/` и `/anime` (обе ходят в репозитории) и `/ws`, где ожидается именно `426 Upgrade Required`: этот код означает, что запрос доехал до PHP, тогда как до #532 тот же запрос получал от Caddy `400 Client sent an HTTP request to an HTTPS server`.
- **Проверка bind — allowlist `127.0.0.1`, а не запрет `0.0.0.0`.** Листенер на `::` открыт ровно так же, а формулировка «нет строки 0.0.0.0» назвала бы его нормальным.

## Лицензионная атрибуция сторонних компонентов в поставке

До этой правки инсталлятор раздавался **без единого лицензионного текста**: ни собственного GPLv3 (корневой `LICENSE` не входил в `build.files`), ни апстримных. `FRANKENPHP_FILES` — allowlist из 20 имён, все `.exe`/`.dll`, поэтому `license.txt` (PHP License 3.01) и `readme-redist-bins.txt`, которые апстрим кладёт в архив **именно под обязательство редистрибуции**, срезались по конструкции. Одновременно не выполнялись: PHP License 3.01 п. 2 и 6 (обязательный acknowledgment «This product includes PHP software…»), Apache-2.0 §4 (OpenSSL 3.5.7, pthreads4w 3.0.0), MIT (FrankenPHP, Meilisearch, Brotli, watcher), Unicode License (ICU 77.1), лицензии кодеков, статически влинкованных в `php_gd.dll`. Единственным закрытым узлом был бандл `qbittorrent-nox` — и то не намеренно: `extractZipToDir()` распаковывает его целиком, вместе с его собственным `THIRD-PARTY-LICENSES/`.

- **Лицензии апстрима извлекаются в `bin/licenses/`, а НЕ в `bin/frankenphp/`.** Очевидное решение — дописать два имени в `FRANKENPHP_FILES` — ломает гейт паритета рантайма: `check-runtime-parity.js#fingerprintDirectory()` хеширует **каждый** недот-файл под `bin/frankenphp/` и сверяет со слепком `scripts/frankenphp-runtime.json`, а слепок по своему же докблоку разрешено перезаписывать только прогоном `--write` на настоящей Windows-машине. Два текстовых файла в рантайм-каталоге сделали бы `runtime-parity` красным до следующей Windows-сборки, ничего не дав взамен. Отдельный каталог `bin/licenses/frankenphp/` гейт не видит вовсе; проверено фактическим прогоном `download-bins`: в `bin/frankenphp/` по-прежнему ровно 20 файлов, все хеши совпадают со слепком побайтово. Статические копии в репозитории вместо извлечения отвергнуты: они разъезжаются с апстримом при бампе версии молча, а извлечение из архива, запиненного по SHA-256, разъехаться не может.
- **`extraFiles`, а не `files`.** `files` кладёт содержимое в `resources/app/…`, то есть на два уровня вглубь от того, что видит получатель; GPL требует указаний «next to the object code». `extraFiles` кладёт `LICENSE.txt` и `THIRD-PARTY-LICENSES/` в корень установки, рядом с `AnimeDB.exe` и `LICENSE.electron.txt`. Проверено настоящей сборкой (`electron-builder --linux dir` идёт по той же ветке `platformPackager.js`, Windows-минуты не нужны). Понадобилось также явное `!resources/third-party-licenses/**` в `files`: вопреки ожиданию, механизм `excludePatterns` для `extraFiles` этот каталог из `resources/app/resources/` не убрал, и он приезжал дважды.
- **Поставочный `frankenphp.exe` — не «MIT».** Это статический Go-бинарь, внутри которого Caddy и его модули: `caddyserver/caddy` v2.11.4 и `certmagic` v0.25.3 (Apache-2.0), **`dunglas/mercure` v0.24.2 и `dunglas/vulcain` v1.4.1 (AGPL-3.0)**, `caddy-cbrotli` v1.0.1 (MIT). Состав подтверждён строками самого поставочного бинаря, а не только `caddy/go.mod` апстрима. Указывать в индексе одну строку «FrankenPHP, MIT» означало бы заявлять лицензию, которой у артефакта нет. Транзитивные ~200 Go-модулей поимённо не перечисляются: полный список с версиями вшит в бинарь самим тулчейном (`go version -m`) и воспроизводится из него, а corresponding source достижим от запиненного апстрим-тега. Та же граница применена к Meilisearch (Rust): указание на тег вместо перечисления крейтов.
- **Corresponding source отдаётся по GPLv3 §6(d) (directions), а не written offer.** Written offer по GPLv2 §3(b) связывает на три года обязательством перед любым третьим лицом и требует физической выдачи исходников по себестоимости — несоразмерно. Все копилефт-компоненты поставляются немодифицированными, поэтому directions на апстрим-тег достаточно; для qBittorrent-nox и Qt это уже сделано публичным build-репо `gpslab/qbittorrent-nox-win-build`, не хватало только указания на него **внутри самой поставки**. **Предусловие релиза:** раздел про исходники самого AnimeDB исполним лишь тогда, когда репозиторий станет публичным. Пока он приватный, тег `v*.*.*` публиковать нельзя — `build.yml` создаёт GitHub Release автоматически, человеческого шага между сборкой и публикацией нет.
- **Сторож живёт в гейте релиза, а не в jest-проверке каталога `bin/`.** `ci.yml` не запускает `download-bins`, поэтому любая проверка «в `bin/` всё на месте» была бы зелёной вакуумно — на пустом каталоге. `release-smoke.js` — единственное место пайплайна, которое видит настоящее дерево поставки, и стоит ровно между сборкой и публикацией. `checkLicenseFiles()` вызывается **до** запуска приложения: сборка без атрибуции не должна публиковаться независимо от того, стартует ли она, и незачем платить за это временем Windows-раннера. Сама функция чистая и покрыта тестами на синтетическом дереве, а `REQUIRED_LICENSE_FILES` привязан к содержимому `resources/third-party-licenses/` отдельным тестом — иначе запись можно было бы удалить из списка, не уронив ничего.
- **Тексты лицензий собраны вручную с апстримов и не генерировались.** Юридический текст, воспроизведённый по памяти, — это не атрибуция, а её имитация.

## Приоритет `LocaleSubscriber` — строго 20, выше `LocaleAwareListener` (issue #557)

`App\EventSubscriber\LocaleSubscriber` был подписан на `kernel.request` с приоритетом по умолчанию (0) с первого коммита класса. `Symfony\Component\HttpKernel\EventListener\LocaleAwareListener` слушает то же событие с приоритетом 15 и на этом шаге раздаёт `$request->getLocale()` всем сервисам с тегом `kernel.locale_aware`. При приоритете 0 подписчик отрабатывал позже — локаль уже была роздана статическим `default_locale: en` из `framework.yaml`, ещё до того как подписчик успевал договорить её по `Accept-Language`. Интерфейс рендерился по-английски независимо от выбранного языка на каждой странице, кроме `settings/index.html.twig` — единственного места, передающего локаль явно (`|trans({}, null, currentLocale)`), что создавало обманчивое впечатление, будто локализация работает.

- **Приоритет — ровно 20, не любое число выше 15.** Обязано быть выше `LocaleAwareListener` (15), чтобы тот прочитал уже договорённую локаль, а не default. Не обязано быть выше `LocaleListener` (16, core) — тот трогает локаль только при routing-атрибуте `_locale`, которого нет ни на одном маршруте приложения. 20 — каноничное значение из документации Symfony для подписчика, договаривающего локаль по `Accept-Language`.
- **Правка одновременно меняет локаль всем трём `kernel.locale_aware` сервисам, не только шаблонам.** `LocaleAwareListener` применяет `$request->getLocale()` ко всем сервисам с этим тегом: `translator.default`, `translation.locale_switcher` и `slugger` (`Symfony\Component\String\Slugger\AsciiSlugger`, если какой-то плагин его автовайрит через `SluggerInterface` — в `app/src/` сам он не используется). Транслитерация `AsciiSlugger` локале-зависима, поэтому слаг, записанный до этой правки и слаг, записанный после неё при том же входном тексте, могут отличаться — единственное место, где эта правка протекает в сохранённые данные, а не только в рендер.
- **Написание кода локали, уходящее в `$request->setLocale()` и в транслятор, — то же, что в `AvailableLocalesProvider::all()`, а не сырой результат `Request::getPreferredLanguage()`.** `getPreferredLanguage()` прогоняет каждый кандидат через приватный `formatLocale()`, который безусловно переписывает разделитель на `_` (`pt-BR` → `pt_BR`, `zh-Hans` → `zh_Hans`). Каталоги плагинов регистрируются по имени файла (`messages.pt-BR.yaml` → каталог `pt-BR`, `Kernel::configureContainer()` → `framework.translator.paths`), переименовать которое нельзя — значит `pt_BR` от `formatLocale()` не находит каталога и интерфейс уезжает в английский целиком. `LocaleSubscriber::restoreDeclaredSpelling()` сопоставляет негоциированное значение (регистронезависимо, с унификацией `-`/`_`) со списком `AvailableLocalesProvider::all()` и возвращает написание из этого списка; при отсутствии соответствия — значение как есть. **Снято (issue #568):** формат локали ограничен в контракте `anime-db/plugin-contracts` v0.16 — `ManifestValidator::validateLocales()` требует голый языковой субтег и отвергает `pt-BR`, `zh-Hans`, `ru_RU`. Региональный код в манифест плагина попасть больше не может, восстанавливать нечего — `restoreDeclaredSpelling()` и `normalizeForComparison()` удалены, `getPreferredLanguage($locales)` уходит в `$request->setLocale()` напрямую.
- **Первый в проекте тест, гоняющий запрос через `$kernel->handle()` (`app/tests/Acceptance/`).** До issue #557 полный набор (1422 теста) был зелёным при полностью сломанной локализации: `LocaleSubscriberTest::testGetSubscribedEvents()` сверял константу саму с собой, а функциональные тесты вызывали подписчик и контроллер напрямую, мимо диспетчера событий — структурно не способны поймать регресс приоритета. `SettingsProxyLocalizationTest` утверждает по содержимому HTML-ответа (`<h1>...</h1>`, `<html lang="...">`), а не по значению `getSubscribedEvents()` или локали транслятора — проверяется сквозной результат. Проверено вручную (временный откат приоритета до 0): тест красный без правки, зелёный с ней. (Тот же ручной прогон изначально гонял и `PluginLocaleSpellingNormalizationTest` — он проверял этот приоритет вместе с восстановлением написания локали, снятым по issue #568, и удалён вместе с ним.)

## `currentLocale` убран из настроек, `setLocale()` переведён на PRG (issue #558)

Зависимость от issue #557 (приоритет `LocaleSubscriber` = 20) была условием: пока `LocaleAwareListener` мог отработать раньше договорённой локали, `settings/index.html.twig` был единственным шаблоном, вручную передающим локаль в `|trans({}, null, currentLocale)`, — замена на обычный `|trans` без #557 сделала бы страницу настроек единственной непереведённой. После #557 это ограничение снято.

- **`currentLocale` удалён из `SettingsController` и шаблона целиком**, включая обходной откат `getLocale() ?? $locales[0]`. Второго источника правды о текущей локали (помимо `Request::getLocale()`, который `LocaleAwareListener`/`app.request.locale` уже поддерживают для всего остального приложения) больше нет.
- **`SettingsController::setLocale()` — PRG, 303 (`Response::HTTP_SEE_OTHER`), не 302.** Образец инъекции `UrlGeneratorInterface` — `Settings/LabelController`, но там дефолтный 302 подходит (те действия не связаны с языком ответа), а здесь 302 разрешил бы клиенту повторить оригинальный метод, не дав гарантии, ради которой PRG вводится. Вместе с редиректом из класса ушли `$request->setLocale()`, `$this->translator->setFallbackLocales(...)` и инъекции `NearestBuiltInLocale`/`translator.default` — эту синхронизацию теперь делает `LocaleSubscriber` сам, на следующем полном HTTP-цикле (GET после редиректа), точно так же, как для любой другой страницы приложения.
- **`reindexSearch()` сознательно остаётся не-PRG.** Ему нужно донести `$reindexStatus` (`success`/`error`) до шаблона; через редирект это потребовало бы flash-носителя (сессионного стораджа), которого в проекте пока нет ни для одного контроллера. Две разные конвенции POST на одном экране (`setLocale` — редирект, `reindexSearch` — рендер на месте) — осознанный компромисс, не недосмотр.
- **Недоступная сохранённая локаль (`unavailableLocale`) — отдельный `<option value="" disabled selected>` первым пунктом, а не "affected" `selected` на исчезнувшем значении.** Сравнение строгое (`in_array(..., true)`) с `AvailableLocalesProvider::all()`. Когда `unavailableLocale` не пуст, ни один обычный `<option>` не помечается `selected`, даже если его значение совпадает с `app.request.locale` (тем языком, в который негоциация откатилась из-за несовпадения Accept-Language со списком доступных, см. `Request::getPreferredLanguage()`): будь оба условия независимыми, у `<select>` без атрибута `multiple` выигрывает последний `selected` по порядку в DOM — обычный пункт, отрендеренный после плейсхолдера, — что тихо вернуло бы старое поведение (протухшая локаль невидима). `value=""` у плейсхолдера — чтобы просроченная локаль физически не могла уйти в POST и получить `BadRequestHttpException`.
- **Подпись плейсхолдера — `settings.language_unavailable_option` с плейсхолдером `%language%`, не отдельная Twig-строка.** Формируется из `locale_endonym()` (issue #461, ICU) — не требует каталога исчезнувшего плагина, поэтому работает даже когда языковой плагин уже снесён.
- **Тест на PRG-редирект и функциональный acceptance-тест дожидаются полного цикла `kernel.request`, а не только вызова `LocaleSubscriber::onKernelRequest()` напрямую.** Прямой вызов подписчика (как делал функциональный тест до #558) договаривает локаль запроса, но не синхронизирует `translator.default` — это отдельно делает core-класс `LocaleAwareListener` (приоритет 15), который срабатывает только при диспетчеризации через `event_dispatcher`. Без этого шаблон после PRG рендерился бы на английском независимо от негоциированной локали, несмотря на верный `$request->getLocale()`.

## Коллизия локали между двумя плагинами-переводами — детерминированный порядок вместо защиты

**Статус: решение принято заранее, кода под него ещё нет.** Записано, чтобы вопрос не
открывали заново.

Постановка: два одновременно **включённых** плагина типа `translation` объявили одну и ту же
локаль, и в приложении активна именно она. Специальной обработки не будет — ни запрета
установки, ни запрета переключения языка, ни предупреждения в UI. Отсутствие защиты в коде —
осознанное решение, а не недосмотр.

- **Почему не строим.** Официальный языковой пакет один, конкурирующих языковых пакетов не
  существует; ситуацию пользователь создаёт себе сам, установив два пакета на один язык.
  Рассмотренные и отклонённые варианты: (1) запрещать установку плагина-перевода, несущего
  локаль, активную в приложении сейчас — тогда возможность поставить пакет начинает зависеть
  от текущего языка, и после переключения языка тот же пакет внезапно нельзя поставить;
  (2) запрещать переключение на локаль, которая дублируется в нескольких плагинах — тогда в
  списке языков появляется невыбираемый пункт, который надо объяснять. Обе механики видимы
  пользователю и платятся всегда, а случай не наступает.
- **Что вместо защиты — единое правило победителя на обоих слоях: лексикографически
  последний id плагина.** В PHP-слое это уже действующее поведение, унаследованное от порядка
  сборки путей (см. запись про issue #451 выше): `InstalledPluginsRegistry::reconcile()`
  пересобирает индекс через `scandir()` по возрастанию id, `PluginLoader::translationPaths()`
  обходит индекс в том же порядке, Symfony резолвит коллизию `(domain, locale, key)` в пользу
  последнего добавленного ресурса.
- **Требование к будущему нативному слою.** ⟳ **Пересмотрено (issue #647).** Историческая
  формулировка сохранена как есть: *Когда `native/` начнёт домёрживать каталоги из
  установленных плагинов, он обязан прийти к тому же победителю: сортировка содержимого
  каталога плагинов по возрастанию id и мердж «поздний перекрывает раннего». Полагаться на
  порядок `readdir()` без явной сортировки нельзя — иначе исход зависит от файловой системы, а
  окно и меню трея на одной локали смогут заговорить разными пакетами.* Код, которого касалось
  это требование, не появился: `native/` каталоги плагинов не домёрживает и не сортирует —
  сборку оверлея целиком делает ядро (`App\Service\Translation\NativeTranslationsOverlayWriter`,
  issue #647), `native/` лишь читает уже готовый файл оверлея на локаль.
- **Детерминизм нативного слоя при этом прочнее, чем у PHP-слоя.** ⟳ **Пересмотрено (issue
  #647).** Историческая формулировка сохранена как есть: *Запись про issue #451 отмечает
  хрупкую предпосылку: правило «последний id» держится на том, что `reconcile()` пересобирает
  индекс целиком, и молча сменится на «порядок установки», если реконсиляцию оптимизируют до
  инкрементальной дописи записи. Нативный слой индекс не читает вовсе и от этой оптимизации не
  зависит.* Утверждение перевёрнуто: победитель коллизии в оверлее определяется порядком
  `InstalledPluginsRegistry::enabled()`, то есть тем же индексом, а не независимым от него
  проходом — поздний по возрастанию id плагин перекрывает раннего, тот же победитель, что и у
  Symfony в переводах приложения (issue #451). Хрупкая предпосылка из записи про issue #451
  распространяется и на нативный слой теперь в равной мере с PHP-слоем.
- **Отменяет часть решения по issue #404.** Буллет «Контракт плагинов не расширяется под
  нативные переводы; домёрживание переводов из каталогов установленных плагинов
  рассматривалось и отклонено» перестаёт действовать: направление выбрано противоположное —
  плагин-перевод сможет везти каталог нативного слоя в JSON. Историческая формулировка в
  записи по #404 сохранена как есть и получит пометку «Пересмотрено» вместе с реализацией,
  а не задним числом. Вместе с ней теряет силу и соседний буллет про «расхождение между окном
  и native-слоем для таких локалей ожидаемо, не баг» — для локалей, покрытых плагином,
  расхождения не будет.

## `CatalogReaderInterface` реализован хостом, per-plugin скоуп (issue #577)

**Факт до фикса, подтверждён запуском, не только статическим анализом.** Плагин с виджетом,
инжектящим `CatalogReaderInterface` в конструктор, ронял сборку контейнера целиком —
`Symfony\Component\DependencyInjection\Exception\RuntimeException` («Cannot autowire
service... argument "$catalogReader"... but no such service exists»), брошенное из
`ContainerBuilder::compile()` внутри `self::bootKernel()`, то есть до обработки первого
запроса. Это не «пустой виджет», это неспособность приложения запуститься с таким плагином
установленным — воспроизведено во временном откате `Kernel::build()`/сервиса/прохода
(`CatalogReaderWidgetBootTest`, см. её докблок) и восстановлено обратно после фиксации текста
исключения.

- **Реализация — `App\Service\Plugin\CatalogReader`, скоуп — `CatalogReaderScopePass`,
  повторяет `PluginDataStoreScopePass`/`SettingsStoreScopePass`/`OwnManifestScopePass`
  дословно**: поиск классов плагина по namespace-префиксу, рефлексия конструктора на нужный
  интерфейс, подстановка per-plugin инстанса через bindings. Регистрируется в
  `Kernel::build()` последним из четырёх — единственный, кому это принципиально (см. ниже).
- **Источник `externalId` — выбран вариант 1 (ленивый резолв), не вариант 2 (только кеш +
  лог); но `CatalogReader` остаётся строго read-only, ничего не сохраняет.** Ранняя версия
  резолвила через `Anime::getExternalId()` и делала `EntityManager::flush()` на успехе — ревью
  (PR #578) справедливо указало, что `CatalogReaderInterface` инжектится в произвольный сервис
  плагина, включая те, что хост вызывает внутри собственной незавершённой единицы работы, а
  безусловный `flush()` коммитит все несохранённые изменения вызывающего, не только свою запись.
  Исправлено: `CatalogReader::resolveExternalId()` сначала читает `Anime::getCachedExternalId()`
  (read-only, без обращения к плагину); если кеш пуст, а у плагина ЕСТЬ свой
  `app.filler`/`app.sync`/`app.search_by_plugin`-сервис (наследует
  `ExternalIdResolutionInterface`), резолвит напрямую через `$resolver->resolveExternalId($sources)`
  — тот же разбор URL, что делает `Anime::getExternalId()` внутри, но без записи и без `flush()`.
  Обоснование резолва (а не только кеша): у Shikimori (мотивирующий плагин issue) есть и филлер, и
  синкер — виджет получает `externalId` немедленно на первом же рендере, не дожидаясь
  `ExternalIdBackfillService` (его синхронно вызывает `SyncSeedMessageHandler` перед pull, #867), который остаётся единственным путём НАПОЛНИТЬ кеш (запись —
  его задача, не `CatalogReader`). `resolveExternalId()` — дешёвый локальный разбор URL (не
  сетевой вызов), поэтому лишний вызов на кеш-промах не в счёт. Резолвер, бросающий исключение,
  перехватывается и логируется как error, `read()` не падает.
- **`CatalogReader` получает `EntityManager` через `ManagerRegistry`, а не напрямую (как
  `PluginDataStore`), тоже по итогам ревью PR #578.** Держать `EntityManagerInterface` в
  свойстве и полагаться на то, что он всегда открыт, ломается тем же способом, что и обсуждается
  в докблоке `PluginDataStore`: если ЛЮБОЙ другой код, разделяющий тот же EntityManager,
  зафлашится неудачно, инстанс закрывается для всех, кто его держит — а `CatalogReader` живёт
  весь скоуп плагина, то есть держал бы закрытый менеджер до конца процесса. `entityManager()`
  берёт свежий инстанс из `ManagerRegistry` на каждый `read()`, с тем же `isOpen()`/
  `resetManager()`-паттерном, что и `PluginDataStore::entityManager()`.
- **Остаточный случай (когда у плагина нет вообще ни одного `app.filler`/`app.sync`/
  `app.search_by_plugin`-сервиса) — не оставлен молчаливым.** Это ровно сценарий из тела
  задачи: только-виджетный плагин без филлера и синкера. `$resolver === null` в этом случае, и
  `CatalogReader` пишет debug-запись в лог («no cached external id... no resolver service»)
  вместо тихого `null` — компромисс между вариантом 1 и вариантом 2: полноценный вариант 2
  (лог на КАЖДЫЙ `null`) не нужен, потому что вариант 1 уже разруливает основной случай, но
  диагностика для действительно нерешаемого случая всё равно нужна, раз задача явно требует не
  оставлять такой отказ необъяснимым. Для плагина, у которого внешнего id нет по природе
  (`type: local`, читает локальные файлы), путь идентичен — тоже `null`, тоже debug-запись;
  отдельно этот случай не размечен, поскольку неотличим от «резолвер есть, но временно не
  резолвит» на уровне `CatalogReader`, и подавлять лог для него означало бы снова гадать по
  метаданным манифеста, которые контракт явно не поручает читать этому классу.
- **Кандидат в резолверы — строго `app.filler`/`app.sync`/`app.search_by_plugin`, НЕ
  `app.entry_widget`/`app.catalog_widget`.** Резолвером плагина выступает его
  филлер/синкер/поиск; виджет, потребляющий `CatalogReaderInterface` (Shikimori
  `RelatedWidget`/`SimilarWidget`, мотивирующий случай) — потребитель `CatalogReader`, а не
  источник для него. Это верно независимо от того, какие интерфейсы виджет наследует —
  формулировка «виджетные интерфейсы наследуют `ExternalIdResolutionInterface`, поэтому годятся
  в резолверы» здесь раньше стояла как причина исключения и устарела: в пакете контрактов открыт
  PR (`anime-db-plugin-contracts#75`), снимающий это наследование у
  `EntryWidgetInterface`/`CatalogWidgetInterface`, а само решение исключить эти два тега при этом
  не меняется.
- **Цикл в графе сервисов ломается структурно ленивой ссылкой (`ServiceClosureArgument`), а не
  проверкой конструктора кандидата в резолверы (найдено ревью PR #578, итерация 4, пересмотрено
  в итерации 5).** Первая версия фикса читала класс каждого тегированного кандидата и пропускала
  его, если он сам `wantsCatalogReader()` — это ловило только прямой случай (резолвер САМ
  тайп-хинтит `CatalogReaderInterface` в конструкторе). Ревью справедливо указало, что граф
  зависимостей плагина ничем не ограничен по глубине: резолвер, который сам не потребляет
  `CatalogReaderInterface`, но зависит (прямо или транзитивно, через сколько угодно
  промежуточных сервисов того же плагина) от хелпера, который его потребляет, формирует ровно
  тот же цикл — `ServiceCircularReferenceException` из `CheckCircularReferencesPass` с путём вида
  `TransitiveFiller -> CatalogHelper -> app.catalog_reader.<id> -> TransitiveFiller`. Рефлексия
  на один уровень вглубь всегда будет отставать. Исправлено убиранием самой проверки:
  `resolverServiceIdsByPlugin()` больше не смотрит на класс кандидата вообще, а
  `catalogReaderServiceId()` оборачивает `Reference` на резолвер в
  `Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument` — `CatalogReader`
  получает `?\Closure $resolverFactory` вместо `?ExternalIdResolutionInterface $resolver` и
  вызывает его лениво внутри `resolveExternalId()`, только на промахе кеша. Symfony's
  `AnalyzeServiceReferencesPass` помечает такой аргумент lazy-рёбром графа, а
  `CheckCircularReferencesPass` не считает lazy-рёбра циклом на этапе компиляции независимо от
  глубины графа — сервис реально запрашивается из контейнера только в момент вызова замыкания, то
  есть уже после того, как контейнер целиком собран. Тесты:
  `testSearchByPluginServiceConsumingCatalogReaderIsStillPickedAsItsOwnPluginsResolver`
  (переименован из `...IsNeverPickedAsItsOwnPluginsResolver` — поведение теперь обратное: такой
  сервис МОЖЕТ быть своим резолвером, цикл больше не образуется) и новый
  `CatalogReaderTransitiveCycleBootTest` — холодная сборка реального контейнера с автовайрингом
  плагинных классов (`TransitiveFiller` зависит от `CatalogHelper`, который тайп-хинтит
  `CatalogReaderInterface`), которую проверка на голых `Definition` в принципе не может
  воспроизвести — граф образуется именно автовайрингом.
- **`CatalogReaderScopePass` обязан выполняться после `TagPluginServicesPass`** — он ищет
  резолвер плагина по тегам `app.filler`/`app.sync`/`app.search_by_plugin`, которые
  проставляет именно `TagPluginServicesPass`. Порядок компилятор-пассов без явного приоритета
  — порядок добавления (`Symfony\Component\DependencyInjection\Compiler\PassConfig::sortPasses()`,
  `krsort()` + `array_merge()` сохраняет порядок внутри одного приоритета) — задокументирован
  явно в докблоке `Kernel::build()` и `CatalogReaderScopePass`, а не оставлен на волю
  случайного порядка `addCompilerPass()`.
- **Найден и не исправлен посторонний баг: `PluginDataStoreScopePass`/`SettingsStoreScopePass`
  передают `new PluginId($pluginId)` сырым объектным аргументом в `Definition`.**
  `Symfony\Component\DependencyInjection\Dumper\PhpDumper::dumpValue()` бросает «Unable to dump
  a service container if a parameter is an object or a resource» на ЛЮБОЙ сырой объект-аргумент
  `Definition` — подтверждено чтением исходника `PhpDumper.php:2040`, не только по памяти
  прошлой сессии. Оба существующих прохода несут этот баг с момента своего появления; он ни
  разу не проявлялся, потому что ни один тест до сих пор не поднимал настоящий
  `KernelTestCase`-контейнер с плагином, реально потребляющим `PluginDataStoreInterface`/
  `SettingsStoreInterface` целиком (со сборкой и последующим кешированием/компиляцией через
  `PhpDumper`, а не только `ContainerBuilder` в памяти, как делают их собственные unit-тесты).
  Это компилируется всегда, не только в debug — `PhpDumper` используется для кеша контейнера в
  любом окружении. `CatalogReaderScopePass` этого не наследует: `PluginId`-аргумент обёрнут в
  отдельный inline `Definition` (`(new Definition(PluginId::class))->setArguments([$pluginId])`),
  который `PhpDumper` умеет разворачивать как `new PluginId(...)` прямо в дампе. Сиблинг-проходы
  сознательно не тронуты — «минимальное корректное изменение», это отдельный баг вне периметра
  issue #577; стоит завести отдельный тикет.
- **Вызов `($this->resolverFactory)()` перенесён внутрь `try` в `resolveExternalId()` (найдено
  ревью PR #578, итерация 5).** Ленивая привязка резолвера (`ServiceClosureArgument` из
  предыдущего пункта) перенесла реальное конструирование плагинного сервиса-резолвера с этапа
  сборки контейнера на первый промах кеша внутри `read()` — то есть именно конструктор сервиса
  теперь может бросить исключение (например, у резолвера-цепочки клиентов с сетевыми
  настройками) в тот момент, когда вызывается замыкание, а не только сам
  `resolveExternalId($sources)`. Первая версия ленивого фикса вызывала замыкание ДО `try`, из-за
  чего исключение из конструктора уходило мимо обработчика `catch (\Throwable)`, который логирует
  «Resolving the external id failed while reading a catalog record» и возвращает `null` — тот же
  класс дефекта, что ревью отмечало для `flush()` вне `try` в более ранней итерации, только
  внесённый новым механизмом. Исправлено: вызов замыкания и `\assert()` перенесены внутрь `try`.
  Тест `testExternalIdIsNullWhenTheResolverFactoryThrows` конструирует `CatalogReader` напрямую с
  фабрикой-замыканием, которая бросает при вызове (а не резолвером, который бросает из
  `resolveExternalId()` — это уже покрыто `testExternalIdIsNullWhenTheResolverThrows`), и
  проверяет, что `read()` не падает и возвращает `externalId: null`.

## Оверлей нативных переводов из плагинов (issue #647)

- **Оверлей собирает ядро, `native/` читает один готовый файл на локаль и о плагинах не
  знает.** `App\Service\Translation\NativeTranslationsOverlayWriter` читает
  `translations/native/<locale>.json` каждого включённого плагина типа `translation`,
  сплющивает в один JSON-файл на локаль и кладёт его в пользовательские данные;
  `native/i18n` мёрджит этот файл поверх встроенного `native/translations/<locale>.json` и
  ничего не знает ни про `plugins.json`, ни про сам факт, что оверлей вообще откуда-то
  собирается. Это сохраняет первую половину решения по issue #404 («`native/` не должен
  ничего знать о `plugins.json`/бизнес-логике») без изменений — отменена была только вторая
  половина, про то, что набор языков нативного слоя расширяется исключительно релизом ядра
  (см. запись выше).
- **Точка вызова — конец `InstalledPluginsRegistry::reconcile()`, внутри того же
  `synchronized()`-колбэка, что и перезапись индекса.** `reconcile()` — единственный
  funnel, через который проходят установка, обновление, удаление и откат плагина (issue
  #420), поэтому писатель вызывается ровно один раз без дублирования в
  `ZipPluginInstaller`/`PluginRemover`. Отказ записи (`NativeTranslationsOverlayException`)
  не перехватывается внутри `reconcile()` и распространяется наружу — установка,
  обновление или удаление плагина не считаются успешными, если оверлей не собрался, так же,
  как и при отказе `writeIndex()`.
- **Два класса отказа при чтении `translations/native/` разнесены по месту, где они
  обнаруживаются.** Каталог постороннего (уже установленного) плагина, который не
  читается, не парсится или не является JSON-объектом, — это `logger->error()` внутри
  `NativeTranslationsOverlayWriter::readPluginNativeCatalogs()`, этот плагин просто
  пропускается, вся операция не падает. Каталог устанавливаемого/обновляемого пакета — это
  `ZipPluginInstaller::assertNativeTranslationsAreReadable()`, вызываемый до перемещения
  файлов в целевую директорию, и его отказ роняет саму установку/обновление. Граница
  проведена так же, как и во всех остальных проверках `ZipPluginInstaller` (синтаксис PHP,
  манифест): пакет, который пользователь ставит прямо сейчас, обязан быть валиден целиком,
  а посторонний уже установленный плагин с испорченным каталогом не должен ронять операции
  над другими плагинами — иначе один сломанный пакет заблокировал бы удаление или установку
  любого другого.
- **Писатель получает контейнерный `InstalledPluginsRegistry` (`safeMode === false`); в
  safe mode нативный перевод не отключается — продуктовое решение, а не следствие
  связывания.** `#[Autowire(lazy: true)]`-параметр в конструкторе `InstalledPluginsRegistry`
  резолвится в тот же контейнерный singleton, чей `safeMode` всегда `false` (в
  `services.yaml` он ничем не биндится); safe-mode-осведомлённый инстанс, который вручную
  строит `Kernel` для до-контейнерной регистрации бандлов (issue #403), до писателя вообще
  не достаёт — у него `reconcile()` не вызывается. Лишить оверлей нативных переводов в safe
  mode было бы возможно (передать флаг явно), но такой цели не было: safe mode защищает от
  краша при бутстрапе ядра, а не выключает то, что уже собрано и лежит на диске.
- **Самолечения нет: оверлей пересобирается только при операциях с плагинами, при запуске
  приложения ничего не проверяется.** `write()` вызывается исключительно из
  `reconcile()`; ни старт приложения, ни `native/`, ни какой-либо периодический процесс не
  сверяют содержимое каталога оверлея с текущим списком включённых плагинов. Ручное удаление
  файла локали из пользовательского каталога до следующей операции install/update/remove
  (или явного `app:plugin:reconcile`) не восстанавливается — `native/i18n` в этом случае
  молча откатится на встроенный каталог для этой локали, тем же путём, что и для локали, для
  которой оверлея никогда не было.
- **Санитизация — фильтр по ключам встроенного `en.json` плюс потолок 1000 символов на
  значение.** Ключ, отсутствующий в `translations/native/en.json` (эталон, читается
  `readReferenceKeys()`), и любое не-строковое значение отбрасываются без исключения —
  оверлей не расширяет набор нативных ключей, а только переопределяет значения уже
  существующих. Потолок в 1000 символов выбран из-за того, что нативный сплэш-шаг переводов
  доезжает до рендерера как аргумент командной строки (`native/window/splash.js`), а Windows
  ограничивает всю командную строку процесса 32767 символами; самая длинная встроенная
  строка сегодня — `dialog.migration_downgrade_message`, 241 символ в `en.json` и 257 в
  `ru.json`; 1000 даёт большой запас под плагинную строку и всё ещё на порядок меньше
  платформенного лимита. Значение сверх лимита не обрезается, а
  отбрасывается целиком с `logger->warning()`: обрезанный перевод читается как баг в самом
  переводе, а отсутствующий — как штатный fallback через уже существующую цепочку
  (`AvailableLocalesProvider`, `native/i18n#resolveCatalog()`).

## `local`-плагины получают ту же машинерию загрузки, что и `integration` (issue #579)

- **Проблема.** `PluginLoader::integrationPlugins()` (приватный фильтр, на котором держались
  `integrationBundles()`, `registerAutoloadForIntegrationPlugins()`, `twigPaths()`,
  `routingFiles()`, `integrationPluginServices()`) фильтровал строго по
  `PluginType::Integration`. Плагин типа `local` устанавливался, попадал в индекс, показывался
  включённым — и не получал ни автолоада, ни бандла, ни сервисов в контейнере, ни вытекающих из
  контейнера возможностей (виджетов, подписки на события каталога, страницы настроек): молча
  мёртвый тип, при том что контракт (`anime-db/plugin-contracts`) его уже полноценно описывает.
- **Решение.** Приватный фильтр переименован в `codePlugins()` и матчит `Integration || Local` —
  оба типа несут код и получают идентичную обвязку. Разница между типами не в наборе
  возможностей, а в том, что `local` не обращается к внешним источникам — это утверждение
  контракта (`PluginType`'s docblock), которое приложение не проверяет и не обязано проверять на
  этапе загрузки (см. пункт "Чего НЕ делается" в issue #579: "local не ходит в сеть" — статический
  гейт реестра, не рантайм-проверка). Публичные методы переименованы вслед за смыслом множества,
  которое они теперь обходят: `integrationBundles()` → `pluginBundles()`,
  `registerAutoloadForIntegrationPlugins()` → `registerAutoloadForCodePlugins()`,
  `integrationPluginServices()` → `pluginServices()` (`twigPaths()`/`routingFiles()` уже были
  безопасно-общими именами, не тронуты). `Kernel.php` и его докблоки обновлены вслед за
  переименованием — включая `@see`-ссылку в докблоке `installedPluginsRegistry()`.
- **`translationPaths()` при отсутствии `translations/` у `local`-плагина — не ошибка, как у
  `integration`, а не как у `translation`.** `translation`-плагин — чисто декларативный: без
  каталога переводов ему нечего делать вообще, поэтому отсутствие каталога — ошибка загрузки
  (`logger->error`). `integration`/`local` — код-плагины, каталог переводов у них опционален и
  сервис-специфичен (свои строки для настроек/виджетов, не ядра) — отсутствие каталога просто
  означает отсутствие своих строк, не поломку плагина. Официальный реестр плагинов
  (`anime-db-plugins`) валидатором **требует** каталог переводов от `local`-плагина при публикации
  — это гейт на входе в реестр (тот же принцип, что и у сетевой проверки выше: статический,
  внешний по отношению к рантайму приложения), а не runtime-инвариант, который стоило бы
  дублировать здесь ужесточением до `logger->error`. Даже если бы реестр не публиковал ничего без
  каталога, локально установленный (не из реестра) `local`-плагин без переводов должен
  продолжать грузиться — так же, как локально установленный `integration`-плагин без переводов
  уже грузится.
- **Тест на реальном контейнере, не только на `PluginLoader`.** `PluginLoaderTest` (юнит) проверяет
  только то, какой список путей/бандлов возвращает `PluginLoader` — он не поймал бы дыру вида
  "список правильный, но `Kernel::configureContainer()`/`registerBundles()` его не читает" или
  "сервис зарегистрирован, но не тегирован тем, что ждёт виджет-реестр". Добавлен
  `tests/Acceptance/LocalPluginServiceBootTest.php` — `KernelTestCase`, холодная сборка
  контейнера (по той же причине, что и у `CatalogReaderWidgetBootTest`/`PluginTranslationBootTest`:
  переиспользование скомпилированного контейнера никогда не тронуло бы фикстурный плагин) с
  фикстурным `local`-плагином из двух сервисов — виджета (`EntryWidgetInterface`) и подписчика
  (`EventSubscriberInterface` на `WatchProgressChangedManuallyEvent`). Тест проверяет: оба сервиса
  есть в контейнере, виджет реально рендерится (доказывает тег `app.entry_widget` и резолв через
  реестр виджетов), подписчик реально получает диспатченное событие (доказывает тег
  `kernel.event_subscriber`). Виджет и подписчик — **разные классы**, а не один класс с обоими
  интерфейсами: комбинация в одном классе воспроизводит независимый, ранее не пойманный баг
  `TagPluginServicesPass` (не пропускает `.abstract.instanceof.*`-определения, которые
  `ContainerBuilder` создаёт для любого автоконфигурируемого `EventSubscriberInterface`, и падает
  с `DuplicateWidgetNameException` на дубликате имени виджета) — тот же класс проблемы, что уже
  чинили `bebca8c` для `PluginDataStoreScopePass`/`SettingsStoreScopePass`/`OwnManifestScopePass`/
  `CatalogReaderScopePass`, но не для `TagPluginServicesPass`. Баг не специфичен для `local` (он
  воспроизвёлся бы и на `integration`-плагине с тем же классом-комбайном) и не входит в периметр
  #579 — оставлен как открытая находка, не исправлен здесь.
- **`plugin_type.local`** добавлен в `app/translations/messages.ru.yaml`/`messages.en.yaml`
  («Локальный»/«Local») — без него плагин `local` отрисовался бы в списке плагинов и в маркете
  сырым ключом перевода. Переводы для остальных языков — в языковом пакете, отдельной задачей вне
  этого репозитория.
- **Не входит в периметр (см. issue #579).** Словарь `PluginType` не менялся; рантайм-проверка
  «`local` не ходит в сеть» не реализована (статический гейт реестра, не задача приложения);
  контракт `local`-плагинов на объявление виджетов не менялся (уже разрешено на уровне контракта,
  см. `anime-db-plugin-contracts` CLAUDE.md); найденный баг `TagPluginServicesPass` (см. выше) не
  исправлен, как заведомо отдельный от темы issue дефект.

## Клиентский JS — один бандл, синхронно в `<head>` (issue #735)

Сборка: `app/assets/js/**` → esbuild → единственный `app/public/js/main.js`, подключённый одним
тегом. До этого 15 файлов подключались семнадцатью тегами `<script>` в шести шаблонах, и список
«что на какой странице» вёлся руками.

- **Почему бандл, а не пятнадцать тегов в layout.** Приложение отдаётся с localhost, поэтому
  привычные доводы (запросы, кэш, размер) здесь не работают — довод ровно один: список
  подключений перестаёт вестись руками, вход генерируется глобом по каталогу. Шаг сборки в проекте
  и так обязателен (`npm start` = `npm run assets && electron .`), стили давно собираются так же.
- **Почему синхронно и в `<head>`.** Внутри бандла лежит `color-mode.js`, который обязан отработать
  до первой отрисовки (иначе на каждой загрузке мигает светлый фон, issue #638), и
  `inline-handlers.js` с делегированным обработчиком `error` — он должен успеть раньше, чем парсер
  дойдёт до первой обложки (issue #633). `defer`/`async` вернули бы оба дефекта; это закреплено
  тестом `AnimeDetailTemplateRenderingTest::testShowRendersTheHostBundleAsASynchronousHeadScript`.
  Работы с DOM на загрузке после #734 ни у кого нет, поэтому раннее исполнение безопасно.
- **Почему `{% block javascripts %}` остался.** Его смысл сузился до ассетов плагинов: манифест
  плагина объявляет свои css/js, и хост рисует их тегами в этом блоке. Убрать блок — молча сломать
  любой плагин со своим js. Бандл хоста при этом обязан идти раньше плагинных скриптов — после
  переноса тега в `<head>` это выходит само, но остаётся требованием и проверяется тестом.
- **Почему `controller.js` первым во входе.** Остальные модули на верхнем уровне зовут
  `registerControl()` и требуют, чтобы `window.Controller` уже существовал. Порядок остальных
  отсортирован явно: `fs.readdirSync` порядка не гарантирует и он различается между файловыми
  системами.
- **Source maps несут исходники (`sourcesContent`).** `build.files` исключает `app/assets/**` из
  поставки, поэтому карта без встроенных исходников в установленном приложении бесполезна.

## Живой прогон приложения как гейт в CI (issues #736, #739, #746)

`npm run shots` поднимает встроенный PHP-сервер, запускает Electron под Xvfb и снимает каждую
страницу в двух темах. Это единственный прогон, который видит приложение целиком: jest грузит
исходники модулей, PHPUnit рендерит шаблоны, собранный бандл не видит ни один из них.

Гейт живёт отдельным workflow `frontend-smoke.yml` с фильтром `paths`, а не джобой внутри
`ci.yml`: фильтр не стартует прогон вовсе, тогда как джоба-фильтр поднимала бы раннер ради решения
«пропустить», а GitHub тарифицирует каждую джобу с округлением вверх до минуты.

**Что прогон ловит:** ошибки JavaScript в консоли страницы (включая намеренный `console.error` из
реестра контролов), незагруженные `<script>`, ответ основного документа со статусом 4xx/5xx,
несобранные ассеты (падает до запуска Electron) и зависшую отрисовку каталога.

**Живучесть прогона (issue #945).** У прогона свой таймаут (`SHOTS_TIMEOUT_MS`, по умолчанию
10 минут — заметно меньше 20 минут `timeout-minutes` джобы): по истечении главный процесс
Electron (pid в маркере `[shots:pid]`) получает SIGUSR2 — не SIGTERM всей группе: тот убил бы Xvfb и
renderer раньше, чем `capture.js` снимет страницу, а Chromium перехватывает SIGTERM сам; через 5 с
SIGKILL получает вся группа (`RunWatchdog` в `lifecycle.js`). Ctrl+C / SIGTERM на `run.js` убивают
группу Electron (она отдельная, `detached`) и PHP-сервер. Сообщение называет последнюю страницу (`capture.js` печатает
маркер `[shots:page] <тема>/<имя> (<url>)`, `run.js` его разбирает). При падении на странице (и по
SIGUSR2) `capture.js` кладёт рядом со снимками `<тема>/<имя>.FAILED.png` и `.FAILED.html` (дамп DOM);
сбой самого снимка не маскирует исходную ошибку. Каталог `shots/` стирается только после
готовности сервера, прямо перед съёмкой: прогон, упавший раньше, не уничтожает прежние снимки.

**Чего не ловит:** визуальные регрессии — сравнения скриншотов с эталонами нет, и снимок съехавшей
вёрстки проходит зелёным; всё, что не доходит до консоли и не меняет статус ответа; ошибки на
страницах, которых нет в списке снимаемых.

**Данные прогона (issue #939).** Прогон работает на копии фикстуры во временном каталоге, а не на
`data/` и `app/var/` разработчика. Фикстура — скрипт, а не готовый файл БД: `scripts/fixture/`
накатывает миграции на пустую базу и вызывает `app:fixture:load` (`SampleAnimeSeeder` + хранилище +
настройки, метки времени зафиксированы), поэтому она не устаревает вместе со схемой. Собирается раз
на процесс, копируется на каждый `createIsolatedEnv()`; базу между сценариями не обнуляют. Если в
фикстуре нет записи каталога, прогон падает, а не пропускает `/anime/{id}`.

## E2E на Playwright (issue #940)

Каркас в `scripts/e2e/`, запуск — `npm run e2e` / `npm run e2e:session`, описание — README §E2E.
Независим от `scripts/shots/` (у снимков своя задача и свой `php -S`; снимки остаются на `dev`).

- **Боевой сервер, не `php -S`.** FrankenPHP с `app/Caddyfile`, `APP_ENV=prod`, `cache:warmup` до
  старта: воркер-режим и `try_files` Caddy дают поведение, которого у `php -S` нет, а страница
  ошибки в `prod` другая, чем в `dev`.
- **Только доверенный ввод.** `el.click()` в `evaluate()` кликает сквозь оверлей с
  `isTrusted=false` и оставляет тест зелёным; `locator.click()` отказывает на перекрытом
  элементе. Запрет — правило ESLint в `eslint.config.js` (блок `scripts/e2e/**`), проверено
  `tests/scripts/e2e-framework.test.js`.
- **Диалоги подменяются в настоящем main-процессе** (`electronApp.evaluate`), код приложения не
  меняется. `scripts/e2e/main.js` подключает настоящие `native/dialog` и `preload.js`, но не
  супервизор (он запускает Windows-бинарники).
- **Без видео.** `recordVideo` в Electron требует ffmpeg (вторая загрузка) и без него виснет на
  загрузке страницы; при падении сохраняются trace и скриншот.

### Первая партия сценариев (issue #941)

- **Метка — тег Playwright.** `covers({routes, features})` из `scripts/e2e/coverage.js` даёт
  `@route:<путь>` / `@feature:<имя>`; матрица читает их из `--list --reporter=json` без разбора
  исходников. Пустая метка — ошибка при объявлении сценария.
- **Источник для кнопок «Заполнить из источника» — офлайн-плагин** `scripts/e2e/plugins/e2e-source`
  (тип `integration`: `local` не может быть filler). Ставится в `PLUGINS_DIR` до старта сервера
  (контейнер Symfony компилируется вместе с ним), режим (`empty` / `images`) читается из файла
  `mode` при каждом вызове. Кадр галереи кладётся в `MEDIA_DIR/<id>/<sha1(url)>.webp` заранее —
  загрузчик отдаёт готовый файл и сети не касается (SSRF-фильтр всё равно закрыл бы loopback).
- **Тот же плагин — sync-источник с удалением** (`SyncRemovalInterface`, `features.sync: true`, режим
  `linkable`: запись без медиа). Синк по умолчанию выключен: сценарий включает его кликом в
  `/settings/plugins`, а источник привязывает кликами через «Поиск по плагинам» («Заполнить существующую
  запись»), чтобы у записи появился кэшированный external id. `remove()` дописывает id в файл `removed`
  рядом с манифестом. Удаление из источника — задача очереди, а её консьюмер в прогон не входит, поэтому
  сценарий сам запускает `messenger:consume` на пару секунд (`consumeQueuedRemovals`). Кэш `findById()`
  хозяина протекает в `app/var/share` рабочей копии: `APP_SHARE_DIR=var/share` в `app/.env` не
  переопределяется окружением прогона — это дефект, заведён как #966 (не свойство каркаса). Пока он не
  исправлен, режимы фикстуры отвечают под разными external id, чтобы ответ одного режима не подменил
  ответ другого в следующем прогоне.
- **`userData` Electron = каталог окружения.** `Accept-Language` выставляет
  `native/accept-language` по `<userData>/config.json`, а PHP пишет язык в `CONFIG_PATH`; в бою это
  один файл, в E2E их совмещает общий каталог. Иначе переключение языка ничего не меняет.
- **`force: true` запрещён ESLint-правилом** наравне со скриптовым кликом (проверено тестом).
- **Подтверждение удаления без sync-плагина — `window.confirm`**: сценарий отвечает на событие
  `dialog` страницы. Bootstrap-модалка (`anime-delete-modal-*`) появляется только когда у записи
  есть источник с удалением из списка; сценария на неё нет.
- **Пагинация проверяется узким окном**: колонок 1 → страница 6 карточек → 7 записей фикстуры дают 2
  страницы (`BrowserWindow.setSize` через `app.evaluate`).

## Импорт из AnimeDB v1 — трансформация записей, а не подмена БД (issue #951)

Команда `app:catalog:import-v1 <каталог установки v1>` читает базу v1 (`app/Resources/anime.db`)
и строит сущности v2 через ORM. Схемы v1 и v2 не совпадают ни в одной таблице, поэтому подмена
файла БД, как при импорте собственного дампа, невозможна. Код — `app/src/Service/Import/V1/`.

- **Чтение** — отдельный `PDO` с DSN `sqlite:file:<path>?mode=ro` и голый SQL (`V1CatalogReader`).
  Обычный `sqlite:<path>` открыл бы файл на запись. Сущностей Doctrine и второго соединения в
  `doctrine.yaml` нет: чужая схема не должна попасть в `schema:validate` и golden-схему.
- **Шов — `Anime::fromV1(V1AnimeRecord, V1AnimeResolverInterface)` на сущности.** Маппинг v1→v2
  (даты, длительность, страна, статус) живёт в фабричном методе агрегата и ходит в его же сеттеры,
  так что инварианты проверяются. DTO и контракт резолвера лежат в слое сущностей
  (`App\Entity\Import\`), поэтому `Entity/` не зависит от `App\Service` (проверяет
  `EntityLayerDependencyTest`). Реализация `V1AnimeResolver` (словари, эвристики, дедуп
  `Label`/`Studio`/`Storage` по имени/пути, репозитории) осталась в `Service\Import\V1`.
  Исторические штампы `dateAdd`/`dateUpdate` пишет только `fromV1()` (по аналогии с `migrate()`
  — приватные поля в области видимости `Anime`); публичного API для записи дат нет, собрать
  `Anime` с произвольными датами без `V1AnimeRecord` нельзя. `@internal` на DTO и фабричном методе.
- **Порядок в фабрике: даты → `episodesCount` → статус.** `Completed` требует `Released`
  (непустой `dateEnd`), а `SeriesAnime::setWatchStatus()` копирует число серий.
  `type != tv` без `date_end` получает `dateEnd = datePremiere`; ТВ без `date_end` — `Watching`
  и запись `SyncReviewItem` (`NeedsCorrection`).
  «Просмотрено» без дат выхода (любой тип) понижается до `Plan`/`Watching`: это отдельная строка
  отчёта и `NeedsCorrection` с текстом про статус, чтобы просмотренное не стало непросмотренным молча.
- **Плохая запись — всё или ничего.** Нарушение инварианта v2 (пустое название и т. п.) откатывает
  транзакцию и превращается в `InvalidV1InstallationException(REASON_INVALID_RECORD)` с `%id%` и
  `%title%`; команда отвечает переведённым текстом и кодом выхода 4.
- **Одна транзакция**: гард `countAll() > 0` (до любой записи) → безусловная чистка
  `sync_tombstone`/`sync_review_item` → persist → flush → commit. Индексы не форсируются: FTS держат
  триггеры, Meilisearch наполняет `AnimeSearchIndexListener`.
- **Название и статус**: эвристика письменности определяет только локаль (`ja` → `ru` → `null`),
  роль всегда `synonym`; метки-статусы («Просмотрено» и др.) расщепляются до дедупа справочников,
  статус по умолчанию — `Completed`.
- **Жанры**: нормализованное имя сверяется с `GenreCode`/`ThemeCode`/`Demographic`, плюс список
  исключений; 18+ ось (`Ecchi`, `Erotica`, `Hentai`, `Yuri`, `Yaoi`) дропается намеренно и считается
  отдельно от «без аналога».
- **Допущение о схеме v1**: имена колонок (`item.storage`, `item.studio`, `name.item_id`,
  `items_genres.genre_id` и т. д.) восстановлены по описанию задачи и проверены только на
  синтетической базе `tests/Support/V1DatabaseBuilder.php`; на живой базе v1 не прогонялись.
