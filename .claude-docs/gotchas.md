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

## История решения: почему у Doctrine STI-дискриминатора в Anime — честная биекция 6↔6, а не 5 ключей на SeriesAnime

Первая версия STI-разбиения `Anime` (задача #58, до ревью) маппила `tv`/`ova`/`ona`/`special`/`music` на один и тот же класс `SeriesAnime` — `#[ORM\DiscriminatorMap]` формально это позволяет (несколько ключей на подкласс), но фича **deprecated** (doctrine/orm issue #3519) и корректна только на чтение (SELECT → выбор PHP-класса по значению колонки). На запись Doctrine хранит для каждого класса ровно одно `discriminatorValue` — последний совпадающий ключ в объявлении карты — так что любой новый/пересохранённый `SeriesAnime` физически схлопывал исходный подтип в одно и то же значение колонки `type`, независимо от того, чем он был на самом деле. Это тихая потеря данных при записи, а не только предупреждение в логе, и то, что в моменте её никто не читает отдельно от общего значения, не гарантирует безопасность в дальнейшем — отматывать назад такую потерю сложнее, чем не допустить её.

Решение (ревью PR #62 по задаче #58): `SeriesAnime` тоже стал `abstract`, а каждому реальному подтипу — свой конкретный класс: `TvAnime`/`OvaAnime`/`OnaAnime`/`SpecialAnime`/`MusicAnime extends SeriesAnime`. Классы намеренно пустые (бизнес-правила подтипы пока не различают) — весь код `episodesCount`/`watchedEpisodes`/`watchNextEpisode()` остаётся в `SeriesAnime`. `#[ORM\DiscriminatorMap]` в `Anime.php` стал честной биекцией 6 значений → 6 классов, без дублей и без deprecated-фичи. Миграция БД не потребовалась — `type` как был дискриминатором, так и остался.

## Даты хранятся как Unix timestamp, а не DATE/DATETIME

SQLite не имеет нативного типа даты — Doctrine хранит `date_immutable`/`datetime_immutable` как обычный TEXT (ISO-строка), что создаёт риск ошибок при смене часового пояса сервера. Вместо этого используется кастомный тип `App\Doctrine\Type\UnixTimestampType` (зарегистрирован в `config/packages/doctrine.yaml` под именем `unix_timestamp`) — хранит колонку как `INTEGER` (эпоха), конвертируя туда-обратно в `\DateTimeImmutable` через `getTimestamp()`/`setTimestamp()`. На уровне PHP-кода сущностей (`Anime`, `Storage`) ничего не меняется — тип виден только в маппинге колонки и DDL миграции.

## KernelTestCase требует phpunit bootstrap="tests/bootstrap.php", а не vendor/autoload.php

Исходный `phpunit.xml.dist` использовал `bootstrap="vendor/autoload.php"`, что было безопасно, пока все тесты были чистыми `PHPUnit\Framework\TestCase` без загрузки контейнера Symfony. Как только появился первый тест на `Symfony\Bundle\FrameworkBundle\Test\KernelTestCase` (issue #73, рендеринг Twig), это привело к `EnvNotFoundException` — `.env`/`.env.test` никогда не подгружались, а `%env(DEFAULT_URI)%` и подобные плейсхолдеры резолвятся только через `Symfony\Component\Dotenv\Dotenv::bootEnv()`, который вызывается в `tests/bootstrap.php`. Решение: `bootstrap="tests/bootstrap.php"` в `phpunit.xml.dist` + явный `<php><env name="APP_ENV" value="test" force="true"/><server name="KERNEL_CLASS" value="App\Kernel"/></php>` блок (без него `KernelTestCase::getKernelClass()` бросает `LogicException` ещё раньше, чем до `Dotenv`).

## base.html.twig использует asset() — нужен отдельно symfony/asset, twig-bundle его не тянет

`symfony/twig-bundle` не зависит от `symfony/asset` напрямую. Функция Twig `asset()` регистрируется через `Symfony\Bridge\Twig\Extension\AssetExtension`, которая требует пакета `symfony/asset` (сервис `Packages`). Без него любой шаблон с `{{ asset(...) }}` (как `base.html.twig`, вызывающий `asset('js/htmx.min.js')`) падает при рендере с `Twig\Error\SyntaxError: Unknown function "asset"`, даже если `TwigBundle` зарегистрирован и `composer install` прошёл успешно. Оба пакета — `symfony/twig-bundle` и `symfony/asset` — нужны вместе для любого шаблона, использующего `asset()`.

## PHPStan (level 8): private-свойство недоступно через union-тип sibling-подклассов, даже из метода класса-владельца

В PHP код внутри метода `Anime` может напрямую писать в приватное свойство `Anime::$metadata` любого другого экземпляра `Anime` (в т.ч. другого конкретного подкласса вроде `TvAnime`) — область видимости `private` определяется классом, где выполняется код, а не типом конкретного объекта. Рантайм это подтверждает (`php -r` тест). Но PHPStan (level 8, версия 2.x) выдаёт `property.notFound` ("Access to an undefined property"), если статический тип переменной — **union из двух и более** sibling-подклассов (`MovieAnime|TvAnime|...`), полученный, например, из `new $classString()` с `$classString` из карты `AnimeType → class-string`. С единственным конкретным подклассом (не union) той же самой ошибки нет — баг проявляется именно на union. Обходной путь без `@phpstan-ignore`/`@var`-переопределений (что запрещено самим PHPStan CLI на level 8 в этом проекте): вынести присваивание приватного свойства в отдельный **приватный метод** (не статическое поле/проперти) того же класса — вызов метода через union-тип PHPStan анализирует нормально, в отличие от прямого обращения к свойству. См. `Anime::assignMetadataFrom()`, используемый из `Anime::migrate()` (issue #60, PR #64).

## ServiceEntityRepository ломает тесты, которые поднимают «сырой» EntityManager в обход контейнера

`Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository` ожидает конструктор `(ManagerRegistry $registry, string $entityClass)`, но по умолчанию (без `doctrine.orm.repository_factory: doctrine.orm.container_repository_factory` в `config/packages/doctrine.yaml`) Doctrine инстанцирует репозитории через `DefaultRepositoryFactory`, который вызывает `new $repositoryClass($entityManager, $classMetadata)` — сигнатура не совпадает, падает `TypeError`. В этом проекте есть тесты (`AnimeTypeMigratorPersistenceTest`, `AnimeDiscriminatorPersistenceTest`), которые намеренно создают `Doctrine\ORM\EntityManager` напрямую через `ORMSetup::createAttributeMetadataConfig()` + `DriverManager::getConnection()`, а не через DI-контейнер — включение `repository_factory` в `doctrine.yaml` на эти тесты не действует (это настройка DI-фабрики, а не самого ORM). Итог: если сущности не нужен репозиторий с собственной бизнес-логикой поверх `findBy`/`findOneBy`, не вешать `#[ORM\Entity(repositoryClass: ...)]` вообще — вместо этого делать обычный автовайрируемый сервис-обёртку над `EntityManagerInterface::getRepository()` (см. `App\Repository\LabelRepository`, issue #75).

## Классы, которые мокаются как зависимость в тестах других классов, не должны быть `final`

PHPUnit не может создать test double (`createStub`/`createMock`) для класса, объявленного `final` (`ClassIsFinalException`). В проекте уже есть неявная конвенция: сервисы, которые представляют собой конечную бизнес-логику и не подставляются моком нигде (`AnimeTypeMigrator`), — `final`; сервисы, которые внедряются в другие классы и мокаются в их тестах (`WsFrameEncoder`, `WsPublisher`, `WsHandshake`, `App\Repository\LabelRepository`), — без `final`. Перед тем как объявить новый сервис `final class`, проверить, не понадобится ли его мокать в тесте потребителя.

## `%kernel.project_dir%` внутри значения env-переменной не резолвится без `resolve:` в `%env(...)%`

`DATABASE_URL=sqlite:///%kernel.project_dir%/../data/data.db` в `.env` — это просто строка для `Symfony\Component\Dotenv\Dotenv`, он не знает о контейнерных параметрах и не подставляет `%kernel.project_dir%`. Если в `doctrine.yaml` написать `url: '%env(DATABASE_URL)%'` (без модификатора), Doctrine получает буквальную строку с `%kernel.project_dir%` как часть пути, PDO пытается открыть файл по несуществующему пути и падает с `SQLSTATE[HY000] [14] unable to open database file` — без явного упоминания, какая часть пути не так. Обнаружено при добавлении второго DBAL-соединения `queue` (issue #97): baг был и в исходном соединении `default`, просто до этого никто не проверял `bin/console dbal:run-sql` — вся существующая интеграция с БД в тестах идёт через отдельный `DriverManager`, в обход контейнера (см. `ServiceEntityRepository`-гочу выше), поэтому реальный `doctrine.dbal.default_connection` из контейнера никогда не коннектился в тестах. Фикс — модификатор `resolve:`: `url: '%env(resolve:DATABASE_URL)%'`. Он говорит Symfony рекурсивно подставить контейнерные параметры (`%kernel.project_dir%` и т.п.) внутрь строки, полученной из env. Актуально для любого нового DBAL-соединения/env-URL, использующего `%kernel.project_dir%` или другой контейнерный параметр.

## FTS5 UNINDEXED-столбец не сравнивается с параметром, привязанным как строка

В `anime_fts` (виртуальная FTS5-таблица, задача #195) столбец `anime_id UNINDEXED` хранит `INTEGER`, но у виртуальных таблиц эффективная афинность столбца — `NONE`: сравнение не приводит типы. `$connection->fetchOne('... WHERE anime_id = ?', [$animeId])` через PDO по умолчанию биндит параметр как **строку** (`PDO::PARAM_STR`), а не как int — в результате `anime_id = '1'` никогда не совпадает с хранимым `1` и запрос молча возвращает 0 строк вместо ожидаемых. Литерал прямо в SQL (`WHERE anime_id = 1`) или явный `PDO::PARAM_INT`/`bindValue()` работает нормально. `AnimeRepository::matchAnimeIdsByName()` этой ловушки не касается — он только читает `anime_id` в SELECT, а фильтрует через `MATCH` (строковый параметр, для которого это ограничение не действует). Актуально для любого будущего кода, который захочет фильтровать `anime_fts` напрямую по `anime_id`.

## csrf_token() в Twig требует активной сессии в RequestStack — падает при прямом рендере шаблона в тестах

`Symfony\Bridge\Twig\Extension\CsrfRuntime` берёт `CsrfTokenManagerInterface` из контейнера, а его дефолтная реализация (`SessionTokenStorage`) читает/пишет токен через `RequestStack::getSession()`. В реальном HTTP-цикле сессия всегда доступна (framework.yaml: `session: true`), но в `KernelTestCase`-тесте, который вызывает `$twig->render(...)` напрямую (как `BaseTemplateRenderingTest`/`SettingsTemplateRenderingTest`), в `RequestStack` ничего не запушено — падает `SessionNotFoundException: There is currently no session available.`. Решение для тестов, рендерящих шаблон с `csrf_token()`: вручную запушить в `request_stack` (сервис) `Request` с `setSession(new Session(new MockArraySessionStorage()))` перед вызовом `render()`.

## flock() на файле, который сам же перезаписывается через temp+rename(), не защищает от гонки

Наивная реализация «read-modify-write под локом» для `plugins.json` (issue #219) выглядит логично: `fopen($path, 'c+')` → `flock(LOCK_EX)` → прочитать → изменить → записать во временный файл → `rename($tmp, $path)` → снять лок. На практике это **не защищает от гонки двух писателей** — эмпирически воспроизведено на Linux (два параллельных PHP-CLI процесса, инкремент общего счётчика, `flock` теряет ~половину инкрементов).

Причина: `rename()` подменяет inode, на который указывает путь `$path`. Если процесс B успел открыть `fopen($path, 'c+')` (получив fd на **старый** inode) ещё до того, как процесс A выполнил `rename()`, то лок процесса B после того, как A его снял, берётся на уже осиротевший старый inode — он больше ничего не блокирует и не видит данных, записанных A через новый inode. B читает старые (пустые/устаревшие) данные и своим `rename()` затирает результат A. Т.е. `flock()` в этой схеме почти буквально не работает, несмотря на формально корректный код.

Решение: лок берётся не на файле данных, а на **отдельном стабильном lock-файле** (`plugins.json.lock`), который никогда не заменяется через `rename()` — его inode не меняется между захватами, поэтому `flock()` реально сериализует писателей. Файл данных (`plugins.json`) по-прежнему пишется через temp+rename — это остаётся нужным для lock-free читателей (atomic rename гарантирует им consistent-снимок). См. `App\Service\Plugin\PluginsConfigStore::update()`.

Актуально для **любого** будущего кода, который захочет реализовать read-modify-write с эксклюзивным логом поверх файла, обновляемого через temp+rename — паттерн лока-на-данных-с-atomic-rename несовместим сам с собой, лок и atomic-replace должны идти на разные файлы.

## `matchingStrategy` — параметр поискового запроса, а не настройка индекса Meilisearch

`PATCH /indexes/{uid}/settings` (и, соответственно, `Indexes::updateSettings()` в `meilisearch/meilisearch-php`) **отклоняет** ключ `matchingStrategy` с `400 Unknown field` — проверено эмпирически на реальном бинарнике 1.13.0 (issue #196). Это не персистентная настройка индекса, а параметр конкретного вызова `POST /indexes/{uid}/search` (тело запроса, наравне с `q`/`filter`). `App\Service\Search\AnimeSearchIndexer::configureIndex()` его сознательно не устанавливает — вместо этого `matchingStrategy: "frequency"` должен передавать любой код, который реально шлёт поисковый запрос (обоснование выбора `frequency` вместо дефолтного `last` — `context/tech_decisions.md` в `anime-db-workspace`, issue #196: дефолт `last` даёт 0 результатов на фразах с предлогами вроде «о тетради смерти»).

## Свежий чекаут — `vendor/` не установлен

Перед `vendor/bin/phpunit`, `composer cs-check`/`cs-fix`, `composer phpstan` внутри `app/` нужно сначала выполнить `composer install --no-interaction --prefer-dist` — свежий чекаут этого не делает автоматически. Без этого `vendor/bin/phpunit: No such file or directory`.

## `App\Service\Plugin\Filler\PluginAnimeDataMerger` — переименован из `AnimeFillApplier`

Сервис, изначально введённый как `AnimeFillApplier` (bulk-fill при скане хранилища), переименован в `PluginAnimeDataMerger` при реализации issue #231 и расширен: теперь также обрабатывает `title` (overwrite) и `cover`/`images` — через новый `PluginMediaDownloaderInterface` → `HttpPluginMediaDownloader` (скачивание по URL в `%AppData%/media/{anime id}/`), а не голым присваиванием URL-строки в колонку. `BulkFillerService` по-прежнему исключает `cover`/`images` из полей, которые передаёт мерджеру — у ещё не персистентной `Anime` нет `id`, а значит нет и директории на диске, куда скачивать (см. `PluginAnimeDataMerger::applyCover()`/`applyImages()`).

## `app-media://` — протокол *чтения*, а не пайплайн скачивания

`native/protocols/app-media.js` (issue #68) только отдаёт уже лежащие на диске файлы из `%AppData%/media/{id}/` по схеме `app-media://anime/{id}/{filename}`. Сервиса, который скачивает внешние (плагинские) URL в этот каталог, до issue #231 в кодовой базе не существовало вообще — не было даже выделенного HTTP-клиента под эту задачу (только `symfony/http-client` как composer-зависимость, использовавшаяся исключительно для Meilisearch). Формулировка «через существующий app-media pipeline» в тексте issue относится не к протоколу чтения, а к самому факту хранения файла в `%AppData%/media/{id}/`, откуда протокол потом его отдаёт — скачивание пришлось реализовывать с нуля (`HttpPluginMediaDownloader`).
