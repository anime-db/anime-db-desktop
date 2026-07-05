---
tags: [memory/repo, gotcha]
---

# Ловушки и неочевидные факты

## FrankenPHP — PHP уже внутри, отдельный PHP не нужен

FrankenPHP — Go-бинарник со **статически вкомпилированным** PHP 8.5. Папка `bin/php/` не содержит PHP-рантайма, DLL или расширений. Там только `php.ini.template`. Не устанавливай и не ищи отдельный PHP.

## PHPRC — это путь к папке, не к файлу

`PHPRC=AppData/AnimeDB` (папка), а не `AppData/AnimeDB/php.ini`. FrankenPHP ищет `php.ini` внутри папки, на которую указывает `PHPRC`. Если передать путь к файлу — настройки не применятся.

## Meilisearch Enterprise несовместима с GPLv3

Meilisearch выпускается в двух вариантах: Community (MIT) и Enterprise (BSL-1.1). BSL-1.1 несовместима с GPLv3. В `download-bins.js` явно указывать Community Edition. Бинарники называются по-разному — проверяй URL.

## Meilisearch — только x64 Windows, нет x32

`meilisearch-windows-amd64.exe` — единственная Windows-сборка. x32 не существует. Аналогично для FrankenPHP: `frankenphp-windows-x86_64.zip`, x32 нет и не будет.

## app/var/ в продакшн — это AppData, не каталог установки

В dev `app/var/` живёт рядом с кодом. В продакшн Electron передаёт `APP_RUNTIME_DIR=AppData/AnimeDB/var` в env FrankenPHP. Symfony пишет кэш и логи туда. В инсталлятор не нужно включать `app/var/` — она создаётся при первом запуске.

## Meilisearch индекс несовместим между версиями

LMDB-индекс от одной версии Meilisearch нельзя открыть другой. При обновлении бинарника — обязателен вайп `AppData/AnimeDB/meilisearch/` и переиндексация из SQLite. Это не баг — так работает Meilisearch. Механизм обнаружения: `versions.json` vs `meilisearch/VERSION`.

## VC++ Runtime для Meilisearch на Windows

Meilisearch скомпилирован с MSVC CRT (динамическая линковка). На системах без Visual C++ Redistributable он не запустится. Включить VC++ Redistributable в NSIS-инсталлер (Этап 1).

## supervisor/, window/, tray/ не знают друг о друге

Эти модули связываются только через `lifecycle/index.js`. Если нужно передать данные между ними (например, порт из supervisor в window) — делай это через `lifecycle/`, а не напрямую. Прямой импорт между ними нарушает архитектурную границу.

## Backoff при рестарте FrankenPHP — порт не меняется

При падении и перезапуске FrankenPHP используется тот же порт, что был найден при первом старте (`port` хранится в closure). Новый поиск порта не происходит. Порт освобождается при выходе процесса и немедленно переиспользуется.

## Worker mode — PHP не перезагружается между запросами

FrankenPHP в worker mode загружает `public/index.php` один раз. Symfony остаётся в памяти между запросами. Статические переменные, синглтоны и состояние сервисов **сохраняются между запросами**. Это отличается от стандартного PHP-поведения. Проектируй сервисы с учётом этого.

## Ротация native-логов — при старте, не в реальном времени

`pruneOldLogs()` вызывается один раз в `start()` каждого supervisor-модуля. Причина: desktop-приложение перезапускается пользователем редко; ни cron-в-Node, ни inotify недоступны без внешних пакетов; хватает ежезапускной очистки. Следствие: если приложение работает более 7 дней без перезапуска — файлы сверх лимита появятся, но будут удалены при следующем старте.

## Лог-поток FrankenPHP/Meilisearch не меняется при backoff-рестарте

`logStream` открывается один раз в `start()` и переиспользуется при каждом backoff-перезапуске дочернего процесса. Это значит, что логи нескольких прогонов процесса в один день идут в один файл. При `stop()` поток закрывается (`logStream.end()`) в exit-обработчике дочернего процесса — после того как процесс завершился, а не до.

## Monolog RotatingFileHandler не ограничивает размер файла

`RotatingFileHandler` ротирует только по дате. Ограничения по размеру (10 MB из issue) не поддерживаются стандартным Monolog без внешних пакетов. Для size-based ротации нужен `SizeRotatingFileHandler` (сторонний пакет). Принято решение: только дата.

## SQLite игнорирует ON DELETE CASCADE/RESTRICT/SET NULL без PRAGMA foreign_keys

`PRAGMA foreign_keys = ON` — настройка уровня **соединения**, а не файла БД: она не сохраняется в самой базе и должна выставляться заново при каждом новом подключении. Без неё все `ON DELETE ...`-конструкции в DDL (например, `anime_studios.studio_id` → `RESTRICT`, задача #48, баг B-20) объявлены в схеме, но SQLite их молча не применяет — `DELETE` просто проходит. Решение: сервис `Doctrine\DBAL\Driver\AbstractSQLiteDriver\Middleware\EnableForeignKeys` (встроен в doctrine/dbal), зарегистрированный в `services.yaml` с тегом `doctrine.middleware`, — выставляет `PRAGMA foreign_keys=ON` на каждое новое соединение. В тестах, поднимающих отдельное DBAL-соединение к `sqlite::memory:` (см. `tests/Unit/Migrations/CatalogSchemaTest.php`), эту прагму нужно выставлять вручную — мидлварь регистрируется только для соединения из контейнера DI.

## Doctrine DBAL 4 убрал старую событийную систему (Events::postConnect и т.п.)

`doctrine/orm: ^3.3` тянет `doctrine/dbal: ^4`, где класс `Doctrine\DBAL\Events` и весь EventManager для соединений **удалены** — попытка `#[AsDoctrineListener(event: Events::postConnect)]` падает с `ClassNotFoundError` уже на `composer install` (post-install `cache:clear` собирает контейнер). Замена — Driver Middleware (`Doctrine\DBAL\Driver\Middleware`), регистрируемый как сервис с тегом `doctrine.middleware` (см. пример выше с `EnableForeignKeys`). Для случаев, специфичных под приложение, пишется класс, реализующий `Middleware::wrap(Driver): Driver` и оборачивающий `AbstractDriverMiddleware::connect()`.

## Doctrine STI: несколько значений дискриминатора на один класс — только для чтения

`#[ORM\DiscriminatorMap]` допускает несколько ключей на один подкласс (используется в `Anime` → `SeriesAnime` для `tv`/`ova`/`ona`/`special`/`music`), но это **deprecated** (doctrine/orm issue #3519) и работает корректно только на чтение (SELECT → выбор PHP-класса по значению колонки). На запись (INSERT) Doctrine хранит для каждого класса ровно одно `discriminatorValue` — им становится **последний** совпадающий ключ в `DiscriminatorMap` (порядок объявления важен!). Т.е. любой новый `SeriesAnime`, сохранённый через ORM, физически запишет в колонку `type` всегда одно и то же значение (в `Anime.php` — `'tv'`, т.к. он стоит последним в мапе), независимо от того, что означал исходный подтип. Нельзя одновременно замаппить обычное `#[ORM\Column]`-поле на ту же колонку, что и дискриминатор — Doctrine бросает `Duplicate definition of column`. Поэтому `SeriesAnime::getType()` **не читает** реальное историческое значение (ova/ona/special/music) — оно вычисляется константой по классу. Проверено вручную через `ClassMetadata::$discriminatorValue` и `SchemaTool` на PHP 8.5 / doctrine/orm ^3.3.

## Даты хранятся как Unix timestamp, а не DATE/DATETIME

SQLite не имеет нативного типа даты — Doctrine хранит `date_immutable`/`datetime_immutable` как обычный TEXT (ISO-строка), что создаёт риск ошибок при смене часового пояса сервера. Вместо этого используется кастомный тип `App\Doctrine\Type\UnixTimestampType` (зарегистрирован в `config/packages/doctrine.yaml` под именем `unix_timestamp`) — хранит колонку как `INTEGER` (эпоха), конвертируя туда-обратно в `\DateTimeImmutable` через `getTimestamp()`/`setTimestamp()`. На уровне PHP-кода сущностей (`Anime`, `Storage`) ничего не меняется — тип виден только в маппинге колонки и DDL миграции.
