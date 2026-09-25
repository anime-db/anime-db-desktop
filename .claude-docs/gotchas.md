---
tags: [memory/repo, gotcha]
---

# Ловушки и неочевидные факты

## FrankenPHP — PHP уже внутри, отдельный PHP не нужен

FrankenPHP — Go-бинарник на базе static-php-cli со встроенным PHP 8.5. Папка `bin/php/` не содержит PHP-рантайма, DLL или расширений. Там только `php.ini.template`. Не устанавливай и не ищи отдельный PHP.

**Уточнение по платформам:** PHP статически вкомпилирован только в Linux-сборке (`frankenphp-linux-x86_64`). В Windows-сборке (`frankenphp-windows-x86_64.zip`, на которую реально собирается приложение) рантайм лежит отдельным файлом `php8ts.dll`, а расширения — подгружаемыми DLL из `ext/`; без них `frankenphp.exe` не запускается. Подробности — запись ниже [«У `frankenphp-windows-x86_64.zip`…»](#у-frankenphp-windows-x86_64zip-на-github-релизах-frankenphpexe-не-самодостаточен--зависит-от-php8tsdll-и-других-dll-из-того-же-архива).

## `frankenphp php-cli` не разбирает PHP-флаги — только `-r <code>`, никаких `-l`/`-v`/`-m`/`-d`

Проверено на реальном бинаре `frankenphp` v1.12.4 (Linux): любой аргумент кроме `-r` трактуется как путь к скрипту, который затем не открывается — `Fatal error: Failed opening required '-l' (include_path='.:') in Unknown on line 0`, код возврата **255 всегда**, независимо от того, что за файл передан. Именно так `#410`/`#478` (`ZipPluginInstaller::assertNoSyntaxErrors()`) дважды подряд ловили один и тот же баг: `-l <file>` выглядит как рабочий вызов и корректно собирается `PhpCliCommand`, но реально не исполняется. Единственная рабочая форма для произвольного PHP-кода — `php-cli -r '<code>'` (см. `PhpCliCommand::forEval()`, `forScript()` — под капотом `build()` теперь приватный, чтобы вызывающий код не мог случайно собрать форму с флагом).

Дополнительно: внутри `-r`-кода `$argv` **не определена** даже если после кода в командной строке идут ещё аргументы (обычный `php -r` в этой ситуации заполняет `$argv[1..]`) — подтверждено тем же реальным бинарём. Любые данные для `-r`-кода передавай через окружение дочернего процесса (`Process`'s `$env` + `getenv()` внутри кода), не через хвостовые аргументы.

Для проверки синтаксиса PHP-файла без `php -l` (раз `-l` не работает через `php-cli`) и без риска исполнить код файла — `token_get_all($code, TOKEN_PARSE)`: гоняет тот же лексер+парсер, что и реальная компиляция, бросает перехватываемый `\ParseError` с номером строки на тех же ошибках грамматики, что и `php -l` (сверено построчно на нескольких кейсах: незакрытая `{`, незакрытая строка, некорректный токен). Не ловит компайл-тайм проверки сверх грамматики (например, невалидную цель PHP-атрибута — `#[Attribute]` не на классе) — это вне периметра синтаксис-чека. `opcache_compile_file()` для этой же задачи не подошёл: требует `opcache.enable_cli=1`, который нельзя ни выставить через `-d` (тот же запрет на флаги), ни через `ini_set()` в рантайме (директива `PHP_INI_SYSTEM`, фиксируется на MINIT) — а в `php.ini.template` этого проекта задан только `opcache.enable`, не `opcache.enable_cli`.

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

## SQLite table-rebuild миграции (DROP TABLE + CREATE + RENAME) при `PRAGMA foreign_keys = ON` каскадно стирают дочерние строки

SQLite не умеет `DROP/ALTER COLUMN`/`ALTER COLUMN TYPE` до версии 3.35 — единственный способ поменять схему таблицы, на которую ссылаются другие таблицы, это ручной rebuild (`CREATE ... __new` → `INSERT ... SELECT` → `DROP TABLE` → `RENAME`), см. `Version20260712000002::down()` и `Version20260801000003::up()` (задача #300). Если на соединении включён `PRAGMA foreign_keys = ON` (а он включён всегда, см. запись выше про `EnableForeignKeys`), `DROP TABLE` выполняет неявный `DELETE` всех строк перед удалением, и этот `DELETE` **запускает foreign-key actions** у всех таблиц, ссылающихся на эту `ON DELETE CASCADE` — те дочерние строки стираются молча, и сам `INSERT INTO ...__new ... SELECT`, который спасает строки родительской таблицы, тут не помогает: он копирует только сам родительский ряд, не выполняет DELETE-cascade *в обратную сторону*. Нашли на `anime` (дочерние: `anime_genres`, `anime_studios`, `anime_labels`, `anime_name`, `anime_external_id`, `anime_description`, `anime_plugin_data`) — ревью PR #304 поймало это до мерджа. Обязательный паттерн для любой rebuild-миграции таблицы с дочерними `ON DELETE CASCADE`-ссылками:
- переопределить `isTransactional(): bool { return false; }` — `PRAGMA foreign_keys` игнорируется внутри транзакции, а Doctrine оборачивает миграцию в транзакцию по умолчанию;
- `addSql('PRAGMA foreign_keys = OFF')` первой инструкцией, `addSql('PRAGMA foreign_keys = ON')` последней — это соответствует официальной 12-шаговой процедуре SQLite для `ALTER TABLE`;
- проверить `PRAGMA foreign_key_check` после — но не через `addSql()` (её результат там просто отбрасывается), а в `postUp()`, читая `$this->connection->fetchAllAssociative(...)` и вызывая `$this->abortIf(...)` при непустом результате;
- тест уровня `tests/Unit/Migrations/*Test.php`, который реально инстанцирует класс миграции (`require_once` файла из `migrations/`, т.к. эта директория не входит в PSR-4 автозагрузку) и гоняет `up()`/`postUp()` против настоящего SQLite-соединения с `PRAGMA foreign_keys = ON`, наполнив таблицу и все дочерние строки заранее — см. `Version20260801000003Test.php`. Чтобы PHPStan видел такой класс (директория `migrations/` не входит в `paths` конфига), в `phpstan.dist.neon` нужен `scanDirectories: [migrations]`.

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

## SQLite `json_extract()` принимает путь как bind-параметр, не только как литерал

Путь (`$.foo.bar`) во втором аргументе `json_extract(column, path)` не обязан быть SQL-литералом времени компиляции — SQLite вычисляет его как обычное выражение, поэтому `json_extract(metadata, ?)` с путём, переданным через bind-параметр PDO, работает так же, как захардкоженная строка (проверено эмпирически, issue #257). Это значит, что при построении пути из динамического, но уже провалидированного значения (например, `PluginId`) не нужно вручную экранировать/интерполировать его в SQL — весь путь целиком уходит биндом, как и любой другой параметр. См. `AnimeRepository::findByExternalId()`/`indexByExternalId()`.

## Индекс плагинов через `require` + opcache в worker mode может отдать устаревшие данные

`InstalledPluginsRegistry::readIndex()` (issue #241) читает сгенерированный `installed-plugins.php` через `require`. В worker mode FrankenPHP держит процесс живым (см. «Worker mode — PHP не перезагружается между запросами»), а opcache кэширует байткод включённых файлов. Поэтому вызов `readIndex()` в том же воркере **сразу после** `reconcile()`, который только что перезаписал `installed-plugins.php`, может вернуть **старый** массив: opcache при `opcache.validate_timestamps` перечитывает файл не мгновенно (`revalidate_freq`, по умолчанию 2с), а без него — вообще до рестарта процесса. На практике безопасно: установка/удаление плагина всё равно требует перезапуска воркера (новый DI-контейнер/набор бандлов — «Atomic Cache Swap»), после которого индекс перечитывается свежим. Ловушка проявится, только если кто-то попытается вызвать `reconcile()` и тут же прочитать результат внутри одного долгоживущего запроса — так делать не нужно; `reconcile()` — install-time операция, а не read-path.

## `claude-code-action` молча пропускает ревью на PR, который меняет `.github/workflows/*`

Джоб `Claude review` (`.github/workflows/claude-review.yml`, `anthropics/claude-code-action`) имеет **защиту целостности**: если workflow-файл в контексте PR **отличается** от версии на дефолтной ветке (`master`), экшен отказывается запускать SDK и **самопропускается**, при этом job завершается **success** (не failure). В логе:

> `Workflow validation failed. The workflow file must exist and have identical content to the version on the repository's default branch.`

Смысл — не дать PR (в т.ч. из форка) подменить ревью-воркфлоу и утащить секреты. Практические следствия:

- **Любой PR, который трогает `.github/workflows/`, не получает Claude-ревью вообще** — оно зелёное, но фактически не выполнялось. Легко принять «зелёный skip» за «ревью прошло». Проверять по наличию комментария от `claude[bot]`, а не только по цвету чека.
- **Отладить сам ревью-воркфлоу (например, включить `show_full_output: true`) с ветки PR нельзя** — любая правка файла = отличие от `master` = skip. Такие изменения (`show_full_output`, смена модели/промпта, пиннинг версии экшена) нужно вносить **прямо на `master`**, а проверять уже последующими PR.

## qBittorrent 5.x WebUI API переименовал ключи `proxy_*` в `getPreferences()`/`setPreferences()`

`scripts/versions.json` пинит `qbittorrentNox: "5.2.3_2"`. В qBittorrent 5.0 секция прокси WebUI API (`src/webui/api/appcontroller.cpp`) была переработана относительно pre-5.0 схемы:

- `proxy_hostnames` → `proxy_hostname_lookup`;
- `proxy_peer_connections` — **не переименован**, ключ существует и в 5.2.3 как есть (это отдельная libtorrent session-настройка, `session->isProxyPeerConnectionsEnabled()`), НЕ путать с новым `proxy_bittorrent`;
- новый ключ `proxy_bittorrent` (`pref->useProxyForBT()`) — отдельный флаг «использовать прокси для BitTorrent-целей» (трекеры/анонсы), появившийся в 5.x;
- `proxy_tracker_connections` — такого ключа в WebUI API нет и не было вовсе;
- `proxy_type` отдаётся/принимается как **строка** (`"None"`, `"HTTP"`, `"SOCKS5"`, `"SOCKS4"`) через `Utils::String::fromEnum`/`toEnum` (Qt `QMetaEnum`, буквальное имя C++ enum-константы) — это верно уже в текущей кодовой базе, трогать не нужно.

Проверено эмпирически по исходникам тега `release-5.2.3` (github.com/qbittorrent/qBittorrent), а не по официальной wiki-документации WebUI API — та на момент проверки (страница "WebUI API (qBittorrent 5.0)") оказалась устаревшей/неточной и показывала ещё домодерновую схему (`proxy_type` как int, без `proxy_hostname_lookup`/`proxy_bittorrent`). При сомнениях по WebUI API — сверяться с реальным C++ source нужного тега, а не с вики.

Последствие бага (issue #347, PR #359): `TorrentProxySynchronizer::CONFIRMED_KEYS`/`socks5Preferences()` изначально использовали pre-5.0 имена — `setPreferences()` их молча игнорировал, а readback в `confirmApplied()` падал при каждом применении SOCKS5, оставляя торренты на паузе навсегда. Тесты это не ловили, потому что мок-readback тавтологично копировал те же (неверные) имена ключей из прод-кода — см. `TorrentProxySynchronizerTest::realQbittorrentPreferencesResponse()`, которая теперь независимо от прод-констант типизирует реальные имена полей.

## PowerShell `Start-Process -ArgumentList` схлопывает массив в одну командную строку — значения с пробелами (`name=`, `program=`) нужно квотить вручную

`native/firewall.js` (issue #361) применяет `netsh advfirewall firewall add/delete rule ...` для TCP+UDP через один elevated (UAC) вызов `Start-Process -FilePath powershell.exe -ArgumentList ... -Verb RunAs`. Win32 `CreateProcess` (в отличие от POSIX `execve`) не получает `argv` как массив — только одну строку командной строки, которую сама программа обязана распарсить сама. `Start-Process -ArgumentList` с массивом строк просто **склеивает элементы через пробел** перед передачей дальше — он не квотит их индивидуально. Поэтому значения с пробелами/скобками — `name=AnimeDB qBittorrent (TCP)` и `program=C:\...\qbittorrent-nox.exe` (путь может содержать пробелы, напр. `Program Files`) — должны нести собственные кавычки внутри самого токена: `name="AnimeDB qBittorrent (TCP)"`, `program="C:\...\qbittorrent-nox.exe"`. `buildNetshCommandLine()`/`quoteNetshArg()` квотируют оба этих ключа (`QUOTED_ARG_KEYS`); остальные (`dir=in`, `protocol=TCP`, `profile=private`, ...) не нуждаются в квотировании, потому что не содержат пробелов.

Изначально TCP и UDP запускались как **два отдельных** `Start-Process -Verb RunAs` — по одному UAC-промпту на протокол за один клик тумблера. Чтобы свести это к одному промпту, оба `netsh`-вызова теперь выполняются внутри **одной** elevated PowerShell-сессии: `Start-Process` поднимает elevated `powershell.exe`, которому скрипт (`netsh ...; netsh ...`) передаётся через `-EncodedCommand` (base64 UTF-16LE), а не как quoted-текст. Это осознанно, чтобы не городить двухуровневое вложенное PowerShell-квотирование (внешний неэлевейтед `-Command` вызывает `Start-Process`, который сам запускает elevated `powershell.exe`, исполняющий netsh) — base64 не содержит пробелов/кавычек, поэтому безопасно проходит оба уровня без экранирования.

## Node CJS-модуль технически разрешает top-level `return`, но babel-jest — нет

Node оборачивает каждый CommonJS-модуль в функцию, поэтому `return` на верхнем уровне файла — валидный JS и реально работает при запуске через `node`/Electron. Но `jest` транспилирует файлы через `@babel/parser` без `sourceType: 'script'`/`allowReturnOutsideFunction`, и падает с `SyntaxError: 'return' outside of function` при попытке смокать/протестировать такой файл (даже если сам тестируемый модуль ни разу не упоминает Jest). Столкнулись при добавлении early-exit в `native/lifecycle/index.js` для проигравшего гонку `requestSingleInstanceLock()` экземпляра (issue #390) — пришлось заменить top-level `return` на `if (gotLock) { ... } else { app.quit(); }`, обернув всё тело файла. Правило на будущее: в `native/` не использовать top-level `return` как приём раннего выхода из скрипта, даже там, где это синтаксически прошло бы через сам Node — использовать `if/else`.

## Процессы-сироты после аварийного завершения Electron — PID-файлы с проверкой имени образа, не только before-quit (issue #390)

`before-quit` — единственное штатное место, где `native/supervisor/index.js` останавливал дочерние процессы (`frankenphp.exe`, `meilisearch.exe`, `qbittorrent-nox.exe`) до issue #390. Оно не срабатывает при падении Electron, принудительном завершении из диспетчера задач или при апдейте поверх работающей копии (см. issue #390) — дочерние процессы остаются висеть, держат порты и файлы БД (`data.db` в WAL-режиме, LMDB Meilisearch), и следующий запуск на них натыкается.

Решение — `native/supervisor/pid-tracker.js`, общий для всех четырёх дочерних процессов: PID пишется в `AppData/AnimeDB/var/pids/<name>.pid` при спавне, удаляется при штатном `stop()`. `killOrphan()` читает оставшийся файл (если он есть — значит предыдущий сеанс не дошёл до `stop()`), проверяет через `tasklist /FI "PID eq <pid>"`, что PID всё ещё принадлежит **тому же имени образа** (не голому факту "процесс с таким PID существует" — ОС может успеть переиспользовать PID для чего-то совершенно другого между сеансами), и только тогда убивает через `taskkill /PID <pid> /F`. Файл-трекер удаляется в любом случае, даже если процесс уже не жив. И `tasklist`, и `taskkill` вызываются с `{ windowsHide: true }` — без этой опции Electron, будучи GUI-процессом без консоли, мигает отдельным чёрным консольным окном на каждый вызов.

**Порядок вызова важен.** `frankenphp.js` и `messenger-consumer.js` спавнят один и тот же бинарник (`frankenphp.exe`, разные аргументы CLI) — у них разные ключи трекера (`LOG_PREFIX`: `'frankenphp'` vs `'messenger-consumer'`), поэтому PID-файлы не конфликтуют сами по себе. Но если бы каждый модуль по-прежнему вызывал `killOrphan()` лениво, внутри собственного `start()` (как было изначально), то к моменту вызова `messengerConsumer.start()` — третьим по счёту в `supervisor.start()`, уже после `frankenphp.start()` — `frankenphp.exe` текущего сеанса уже запущен и мог получить PID, переиспользованный ОС из `messenger-consumer.pid` предыдущего аварийного сеанса. Проверка по имени образа в этом случае прошла бы (это ведь и правда `frankenphp.exe`), и `killOrphan()` убил бы собственный, только что стартовавший backend — с виду обычный backoff-рестарт в логе, ищи потом причину. Исправлено: каждый модуль экспортирует `killOrphan()` отдельно от `start()`, и `native/supervisor/index.js` вызывает все четыре одним `Promise.all()` в начале своего `start()`, до того как запущен хоть один дочерний процесс текущего сеанса — тогда убивать нечего, PID текущего сеанса ещё не существуют (issue #390, ревью PR #395).

Отдельно, `process.on('exit')` в `lifecycle/index.js` вызывает `supervisor.killSync()` — синхронный `child.kill('SIGKILL')` без ожидания промиса, единственный вариант, доступный внутри `'exit'`-обработчика Node. Он не заменяет `killOrphan()` (не помогает при жёстком `taskkill /F AnimeDB.exe`, где вообще не остаётся исполняемого JS), а покрывает случаи вроде `process.exit()`, вызванного откуда-то ещё в главном процессе в обход `before-quit`.

## Любой новый спавн PHP-процесса обязан собирать env через `native/supervisor/env.js`, не заводить свою копию (issue #391)

До issue #391 `frankenphp.js` и `messenger-consumer.js` собирали env для дочернего PHP-процесса каждый своей независимой `buildEnv()`. Копии разошлись: у `messenger-consumer.js` отсутствовал `PLUGINS_DIR` (и `QBITTORRENT_URL`), из-за чего `Kernel::installedPluginsRegistry()` откатывался на `var/plugins` внутри каталога установки вместо `AppData/AnimeDB/plugins` — обработчик фоновых задач (`messenger:consume`) видел не тот набор установленных плагинов, что веб-воркер, без единой ошибки в логе. Риск усиливается тем, что набор бандлов плагинов, вычисленный из `PLUGINS_DIR`, запекается в `<Container>.bundles.php` (см. докблок `Kernel::initializeBundles()`) при первой компиляции контейнера — если первым бутом окажется будущий консольный вызов схемы (`doctrine:migrations:migrate`, `messenger:setup-transports`) с неполным env, испорченный dump-контейнер молча переиспользуется всеми последующими процессами.

Решение — `native/supervisor/env.js`: `buildCommonEnv(context)` возвращает полный набор переменных, обязательных для **любого** PHP-процесса (все пути к пользовательским данным — `PLUGINS_DIR`, `PLUGINS_CONFIG_PATH`, `CONFIG_PATH`, `MEDIA_DIR`, `APP_RUNTIME_DIR`, `DATABASE_URL`/`QUEUE_DATABASE_URL`, а также `QBITTORRENT_URL`/`OAUTH_CALLBACK_ORIGIN`); `buildWebWorkerEnv(context, wsPort)` добавляет `APP_PORT`/`WS_PORT` — переменные, осмысленные только для веб-воркера FrankenPHP. `frankenphp.js`, `messenger-consumer.js` и `search-reindex.js` — тонкие обёртки над этими двумя функциями. Любой будущий спавн PHP (в т.ч. консольные вызовы схемы) обязан использовать `env.js`, а не собирать `process.env`-объект самостоятельно.

Контекст (`PhpContext`: `appPort`, `qbittorrentPort`, `meiliPort`, `meiliKey`) собирается **один раз** в `supervisor/index.js` и передаётся дальше целиком, объектом. Позиционных аргументов здесь сознательно нет: три из четырёх полей — числа-порты, их перестановка не ловится ни линтом, ни JSDoc и проявляется только в рантайме. `APP_PORT` в `buildWebWorkerEnv()` берётся из того же `context.appPort`, что и `OAUTH_CALLBACK_ORIGIN`, — отдельным параметром порт не принимается, чтобы два значения одного порта не разъехались.

## Любой разовый консольный вызов PHP обязан идти через `native/supervisor/php-command.js`, а не через прямой `spawn`/`execFile` (issue #400)

В отличие от долгоживущих процессов (`frankenphp`, `meilisearch`, `qbittorrent`, `messenger-consumer`), у разовых консольных команд (`app:search:reindex`, `messenger:setup-transports`) нет ни собственного супервизора с backoff, ни healthcheck — они спавнятся, отрабатывают и завершаются. До issue #400 каждый такой вызов был написан заново и по-своему: `search-reindex.js` спавнил процесс с `stdio: 'ignore'` (без лога — падение недиагностируемо) и без таймаута; `messenger-consumer.js` писал вывод в лог, но тоже без PID-трекинга. Общий риск: у обоих промис резолвился только по событию `exit` и оба ожидались внутри `supervisor.start()` — зависший процесс (заблокированный `data.db`/`queue.db`, недоступный Meilisearch) вешал бы старт приложения навсегда, а PID никто не трекал, так что `killOrphan()` о нём не знал и следующий сеанс упирался бы в чужую блокировку той же базы без единой подсказки почему.

Решение — `native/supervisor/php-command.js`: `run(command, args, context, timeoutMs, options)` собирает env через `buildCommonEnv()` (не сам), пишет stdout/stderr в лог-поток `logrotate.js` (имя файла — от `command` с заменой `:` на `-`, поскольку `:` не валиден в имени файла на Windows, если не переопределено через `options.name`), убивает процесс по истечении `timeoutMs` (значение задаёт вызывающая сторона — у `messenger:setup-transports` это секунды, у `app:search:reindex` — существенно больше), трекает PID через `pid-tracker.js` на время вызова. По умолчанию (`options.rejectOnNonZero` не передан либо `true`) отклоняет промис при ненулевом коде выхода, таймауте или ошибке спавна, включая в текст ошибки хвост собранного вывода. При `rejectOnNonZero: false` промис вместо этого всегда резолвится `{ code, stdout, stderr }` (`code: null` при таймауте) — для вызывающих, которым нужно ветвление по конкретным кодам, а не единая трактовка «любой ненулевой код — ошибка». `killOrphan(command)` зачищает зависший процесс наравне с долгоживущими — вызывается в `supervisor/index.js` в том же начальном `Promise.all()`, до старта любого дочернего процесса текущего сеанса (та же причина упорядочивания, что и у остальных `killOrphan()`, см. выше).

`native/supervisor/migrations.js` использует `php-command.js` с `{ rejectOnNonZero: false, name: 'migrations' }` для всех трёх разовых консольных команд бутстрапа (`doctrine:migrations:up-to-date`, `app:database:backup`, `doctrine:migrations:migrate`) — единый `name` заставляет их делить один и тот же лог-файл и PID-слот, как и раньше, когда весь бутстрап писал в один поток. Ветвление по `STATUS_UP_TO_DATE`/`STATUS_OUT_OF_DATE`/`STATUS_DOWNGRADE` в `migrations.js::run()` работает поверх резолва `{ code, ... }`, а не reject — это и была причина, по которой модуль изначально держал собственный спавн; после того как `php-command.js` научился резолвиться кодом по флагу, необходимость в дублирующей реализации отпала.

## Бэкап `data.db` перед миграциями — `VACUUM INTO`, а не копирование файла; ретрай миграции работает только потому, что файл восстанавливается целиком (issue #392)

`data.db` (соединение `default`) работает в режиме rollback-journal — WAL включён только для `queue` (`app/src/Doctrine/Middleware/EnableWalJournalMode.php`, `#[AsMiddleware(connections: ['queue'])]`). После аварийного завершения предыдущего сеанса рядом с `data.db` может лежать «горячий» `data.db-journal`. Простое копирование одного файла `data.db` без журнала снимает несогласованный снимок (мид-транзакция), тогда как оригинал сам восстановился бы при следующем открытии SQLite. `native/supervisor/migrations.js::createBackup()` поэтому не копирует файл, а вызывает Symfony-команду `app:database:backup` (`App\Command\DatabaseBackupCommand`), которая делает `VACUUM INTO ?` через Doctrine `Connection::executeStatement()` — SQLite поддерживает связанный параметр в `VACUUM INTO` (проверено эмпирически: `PDO::prepare('VACUUM INTO ?')->execute([$path])` работает), результат всегда самодостаточный committed-файл без журнала.

`restoreBackup()` при неудачной миграции не просто копирует бэкап обратно — сначала удаляет `data.db-journal`/`-wal`/`-shm` рядом с `data.db`. Без этого шага повторное открытие восстановленного файла нашло бы журнал, записанный для **другого** (уже удалённого) состояния файла, и попыталось бы применить его как rollback — рассинхронизация, а не откат.

Повторный прогон `doctrine:migrations:migrate` после провала не идемпотентен сам по себе: как минимум `Version20260801000003`/`Version20260812000000` объявляют `isTransactional(): false` и перестраивают таблицы через `PRAGMA foreign_keys = OFF` + `CREATE TABLE <table>__new` + копирование + `DROP`/`RENAME`. Обрыв посередине оставляет в БД `<table>__new` без записанной версии миграции — второй прогон уже упёрся бы в «`<table>__new` already exists» и падал бы всегда. Ретрай в `migrations.js` работает только потому, что перед второй попыткой файл `data.db` целиком заменяется бэкапом (тем самым `<table>__new` пропадает вместе со всем остальным состоянием после первой неудачной попытки) — если бы ретрай просто вызывал `migrate` второй раз без восстановления файла, для non-transactional миграций он был бы гарантированно бесполезен.

## `class_exists()` в плагинных compiler pass'ах — только после фильтра по неймспейсу плагина (issue #287, #458)

Каждый compiler pass плагинной системы (`TagPluginServicesPass`, `PluginDataStoreScopePass`, `OwnManifestScopePass`, `SettingsStoreScopePass`) идёт по **всем** определениям контейнера, не только по плагинским, и должен опознать, каким классам можно доверять рефлексию. `class_exists($class)` для этого не подходит как первая проверка: в контейнере есть определения от сторонних бандлов (`doctrine.orm.validator.unique`, регистрируется безусловно `doctrine/doctrine-bundle`'ом), чей класс наследуется от родителя из пакета, которого нет в `vendor/` (`Symfony\Component\Validator\ConstraintValidator` — `symfony/validator` в этом приложении не установлен). `class_exists()` в таком случае не возвращает `false` — он **фатально роняет процесс** при попытке автозагрузки (класс-родитель не резолвится в момент `extends`).

Фатал условен: пока `$namespacePrefixes === []` (плагинов не установлено), все четыре прохода выходят раньше цикла и до `doctrine.orm.validator.unique` дело не доходит. Он срабатывает, как только в индексе появляется хотя бы **один** плагин любого типа — включая `type: translation`, у которого нет ни `src/`, ни сервисов. Это ломает `bin/console cache:warmup` при холодной компиляции (см. `PluginCacheWarmer`), а значит и установку любого плагина: `ZipPluginInstaller` откатывает установку при неуспешном прогреве.

Правильный порядок — сперва `matchPluginId($class, $namespacePrefixes)` с `continue` при `null`, и только для класса, который действительно принадлежит плагину, `class_exists($class)`:

```php
$pluginId = $this->matchPluginId($class, $namespacePrefixes);
if ($pluginId === null) {
    continue;                    // чужой класс — не трогаем вовсе, class_exists() не вызываем
}

if (!class_exists($class)) {
    continue;
}
```

`TagPluginServicesPass` получил этот порядок при исправлении issue #287. Три прохода, добавленные позже (`PluginDataStoreScopePass`, `OwnManifestScopePass`, `SettingsStoreScopePass`, issues #299/#316/#323), скопировали общую структуру `matchPluginId()`/`class_exists()`, но инвертировали порядок двух проверок — регрессия, исправленная в issue #458. Регрессионный тест — `tests/Unit/Service/Plugin/DependencyInjection/Compiler/ForeignDefinitionClassExistsOrderTest.php`, гоняет все четыре прохода против собственной фикстуры (класс с заведомо нерезолвящимся родителем, объявленным лениво через `spl_autoload_register`, вне какого-либо плагинного неймспейса) — не завязан на `doctrine.orm.validator.unique`, чтобы не позеленеть просто оттого, что `symfony/validator` когда-нибудь попадёт в зависимости. Правило актуально для **любого** будущего compiler pass'а, который перебирает `$container->getDefinitions()` и хочет отфильтровать их по принадлежности к плагину: `matchPluginId()` — всегда первая проверка, `class_exists()`/рефлексия — только после неё.

## `console.error` в главном процессе собранного Electron-приложения не попадает никуда

У дочерних процессов (`frankenphp`, `meilisearch`, `qbittorrent-nox`, `messenger-consumer`) есть файловые логи через `native/supervisor/logrotate.js`, подключённые в каждом супервизоре. У самого главного процесса (`lifecycle/index.js`) такого лога не было — в собранном GUI-приложении под Windows у процесса нет консоли, `console.error` пишет в никуда, и `process.on('uncaughtException')` до issue #390 (ревью PR #395) молча убивал все дочерние процессы и завершал приложение без единой строчки диагностики. Исправлено: `logCrash()` в `lifecycle/index.js` синхронно (`fs.appendFileSync`, не поток) дописывает стек в `AppData/AnimeDB/var/log/main-YYYY-MM-DD.log` — та же схема именования, что и у остальных логов (`todayStr()` из `logrotate.js`, экспортирован специально для этого) — и обёрнут в `try/catch` best-effort, поскольку показать `dialog.showErrorBox()` и выйти важнее самого факта записи в лог. `uncaughtException` теперь не подменяет штатное поведение Electron (диалог с ошибкой), а явно его воспроизводит перед `app.exit(1)`.

## `frankenphp php-cli` не понимает CLI-SAPI-флаги (`-l`, `-m`, `-v`) — только путь к скрипту либо `-r <code>` (issue #471)

Обнаружено при реализации `scripts/check-runtime-parity.js`: у сабкоманды `php-cli` (исходник — `caddy/php-cli.go` в репозитории `php/frankenphp`) нет собственного разбора флагов — `cmd.DisableFlagParsing = true`, и весь `os.Args[2:]` идёт в `cmdPHPCLI` как есть. Единственная развилка внутри: если `args[0] === '-r'`, выполняется `args[1]` как инлайн-код (`frankenphp.ExecutePHPCode`); в любом другом случае `args[0]` **всегда** трактуется как путь к PHP-скрипту, который нужно `require`-нуть (`frankenphp.ExecuteScriptCLI`). Никакого распознавания `-l`/`-m`/`-v`/`--help` как флагов нет вообще — они уходят в `require($argv[0])` и падают с `Failed opening required '-l' (include_path=...)`, а не с осмысленной ошибкой использования.

Это напрямую ломает `App\Service\Plugin\ZipPluginInstaller::assertNoSyntaxErrors()`, собирающий `PhpCliCommand::build(\PHP_BINARY, '-l', $file->getRealPath())`: под системным PHP в CI/деве `-l` — валидный флаг синтаксис-линта, под боевым `frankenphp.exe php-cli -l <file>` — fatal error на каждом файле каждого устанавливаемого плагина.

**Ключевое — код возврата.** Проверено на реальном бинаре v1.12.4:

```
$ frankenphp php-cli -l good.php   Fatal error: Failed opening required '-l'   код 255
$ frankenphp php-cli -l bad.php    Fatal error: Failed opening required '-l'   код 255
```

255 **и на синтаксически верном файле, и на битом** — различить их невозможно. А `assertNoSyntaxErrors()` считает синтаксической ошибкой любой ненулевой код и копит `PluginSyntaxError`. Значит в упакованном приложении отклоняется **каждый** плагин, содержащий хоть один `.php`-файл, включая полностью корректные, причём с текстом, который `parseSyntaxErrorMessage()` разобрал из фатала самого FrankenPHP. Это не «линтинг работает не как задумано», а неработоспособность установки плагинов из ZIP целиком.

Формально это отдельный баг с тем же классом симптомов, что и issue #410 (там был пропущен сам `php-cli`-префикс; здесь префикс есть, но конкретно `-l` всё равно не работает, поскольку `php-cli` не является полноценной CLI SAPI обёрткой). То есть исправление #410 не пережило собственной проверки на боевом бинаре. Заведено отдельной задачей — issue #478.

Обратная сторона того же правила: `PluginCacheWarmer` собирает `PhpCliCommand::build(\PHP_BINARY, $this->consolePath(), 'cache:warmup')`, где первым аргументом идёт **путь к скрипту**, а не флаг. Эта форма рабочая, и вармер править не нужно. Баг не в `PhpCliCommand`, а в единственном месте, которое передаёт ему флаг.

Практическое следствие для любого кода, которому нужно получить факт из `php-cli` (список расширений, версию ICU и т.п.), а не просто выполнить `bin/console <command>` (это работает, `args[0]` там — реальный путь к `console`): единственный рабочий способ — `php-cli -r '<инлайн PHP-код>'`, никогда не флаги вида `-m`/`-l`/`-v`.

## У `frankenphp-windows-x86_64.zip` на GitHub-релизах `frankenphp.exe` **не самодостаточен** — зависит от `php8ts.dll` и других DLL из того же архива

Проверено `objdump -p` на `frankenphp.exe` из `frankenphp-windows-x86_64.zip` (issue #471): в таблице импорта, помимо системных `api-ms-win-crt-*`/`KERNEL32.dll`/`VCRUNTIME140.dll`, есть `php8ts.dll`, `brotlidec.dll`, `brotlienc.dll`, `pthreadVC3.dll`, `libwatcher-c.dll` — все они лежат рядом в том же ZIP, отдельными файлами. `scripts/download-bins.js` при этом достаёт из архива **только** `frankenphp.exe` (`zipEntry: 'frankenphp.exe'`) и выбрасывает всё остальное, включая эти DLL. На Linux-сборке (`frankenphp-linux-x86_64`, публикуется отдельным файлом, не ZIP) это не воспроизводится — там бинарь полностью статический и `version`/`php-cli` работают без каких-либо файлов рядом. Не проверено на живой Windows/Wine — но по таблице импорта запуск `frankenphp.exe` без `php8ts.dll` рядом ожидаемо упадёт с ошибкой отсутствующей DLL при старте.

**Вторая половина того же:** расширения в Windows-сборке — не статика, а подгружаемые DLL. В архиве лежат `ext/php_intl.dll`, `ext/php_mbstring.dll`, `ext/php_curl.dll` и прочие, плюс ICU-библиотеки `icudt77.dll`/`icuin77.dll`/`icuuc77.dll`/`icuio77.dll`. Ни одна из них не извлекается, а в `bin/php/php.ini.template` нет ни строки `extension=` и не задан `extension_dir`. Даже если положить рядом `php8ts.dll`, ни одно расширение не загрузится.

Весь архив — это 80 файлов и ~160 МБ полноценного Windows-дистрибутива PHP (`php.exe`, `php-cgi.exe`, `phpdbg.exe`, `php.ini-development`, каталог `dev/` с заголовками), а не бинарь с парой библиотек.

Отсюда следует, что запись в [`architecture.md`](architecture.md) про «расширения статически вкомпилированы (… intl …)» **для Windows-сборки неверна** — она верна для Linux-сборки, откуда, вероятно, и пришла. На этой записи строились решения issue #461 (эндонимы локалей через ICU) и #467 (`platform-check: true`): сами решения остаются в силе, но их обоснование опиралось на неверный факт.

Заведено задачами: [#477](https://github.com/anime-db/anime-db-desktop/issues/477) — упаковка, [#479](https://github.com/anime-db/anime-db-desktop/issues/479) — правка `architecture.md`.

## Caddyfile: `{env.X}` в адресе сайта не работает — только в директивах, а не в самом адресе

Caddy различает две подстановки: `{env.X}` — рантайм-плейсхолдер, разбирается при обработке запроса; `{$X}` — препроцессорная подстановка Caddyfile, разбирается до адаптации конфига. Адрес сайта (`:{...}` или `host:{...}` перед `{`) разбирается **на этапе адаптации**, до рантайма — там годится только `{$X}`. Внутри директив блока (`root * {env.APP_ROOT}/public`) `{env.X}` работает нормально, потому что это уже рантайм-контекст. `app/Caddyfile` до issue #532 использовал `{env.APP_PORT}`/`{env.WS_PORT}` в адресах сайтов и падал на любой машине при адаптации: `parsing key: invalid port '{env.APP_PORT}': strconv.Atoi: parsing "{env.APP_PORT}": invalid syntax`. Приложение не стартовало вообще — `frankenphp.js` уходил в backoff-цикл, а `waitForHealth` ждал порта, которого никогда не будет, и через 30 секунд отдавал отказ.

При той же правке всплыли ещё три независимых дефекта одного файла:
- без `admin off` в глобальном блоке FrankenPHP поднимает неаутентифицированный admin API Caddy на `127.0.0.1:2019`; на машине, где порт уже занят другим процессом, это выглядит снаружи как обычный сбой запуска (тот же generic backoff), хотя причина не в APP_PORT/WS_PORT вообще;
- хост-литерал в адресе сайта (даже IP, например `127.0.0.1:{$WS_PORT}`) заставляет Caddy завести `tls_connection_policies` для этого сайта несмотря на `auto_https off` — `auto_https off` отключает только выпуск сертификатов и редирект, не TLS на listener'е. Проверено `frankenphp adapt` + живым `curl`: `curl http://127.0.0.1:<port>/` на такой адрес отвечает `400 Client sent an HTTP request to an HTTPS server`. Адрес без хоста (`:{$PORT}`) в TLS не уходит — так сделаны оба блока сейчас;
- хост в адресе сайта — матчер заголовка `Host`, не bind-адрес: сокет всё равно слушает `0.0.0.0`, если явно не сузить директивой `bind`. Локальность обоих server-блоков (`APP_PORT`, `WS_PORT`) в текущем `app/Caddyfile` держится на `bind 127.0.0.1` в каждом блоке, а не на хосте в адресе.

Все четыре факта проверены на пине `frankenphp 1.12.4` из `scripts/versions.json` (Linux-сборка, `frankenphp adapt`/`validate`/`run` + `curl`) — Caddyfile-адаптер платформенно-независимый Go, поведение на Windows то же.

## Настройки WebUI qBittorrent живут в `[Preferences]` ключами `WebUI\<Имя>`, а не в секции `[WebUI]`

`qBittorrent.ini` не имеет секции `[WebUI]`. Всё, что относится к веб-интерфейсу, хранится в `[Preferences]` под ключами с префиксом: `WebUI\Port`, `WebUI\Address`, `WebUI\LocalHostAuth`, `WebUI\HostHeaderValidation`. Неизвестные секции qBittorrent **молча игнорирует** — ошибки не будет, просто настройки не применятся.

До issue #552 `seedConfig()` писал их как `[WebUI]` + `Port=`/`LocalHostAuth=`. Последствия:

- `LocalHostAuth` не применялся → WebUI требовал аутентификацию → `GET /api/v2/app/version` отдавал **403** → `waitForHealth()`, который ждёт строго 200, ждал до таймаута → `qbittorrent.start()` реджектился → **супервизор ронял запуск всего приложения**, FrankenPHP не стартовал вовсе;
- `WebUI\Address` не применялся → веб-интерфейс биндился на все интерфейсы вместо loopback;
- `WebUI\Port` при этом «работал», потому что дублируется флагом `--webui-port` при спавне, — и это маскировало отказ остальных трёх ключей.

Признак применившегося `Address` виден в собственном логе qBittorrent: он печатает `To control qBittorrent, access the WebUI at: http://127.0.0.1:18080` вместо `http://localhost:18080`.

Секции `[LegalNotice]`, `[BitTorrent]` и `[Network]` — настоящие, там `seedConfig()` пишет правильно; ошибка была только в WebUI. Осиротевшая `[WebUI]` на существующих установках инертна и намеренно не вычищается.

## php.ini должен существовать до ПЕРВОГО PHP-процесса сеанса, а первым идут миграции

Расширения Windows-сборки — подгружаемые DLL (issue #477), и путь к ним даёт только `extension_dir` из php.ini. Без него боевой `frankenphp.exe` стартует вообще без расширений, а `bin/console` падает на `vendor/composer/platform_check.php` с `require the following PHP extensions: gd, intl, openssl, pdo_sqlite, zip` — ещё до первой строки Symfony.

До issue #552 `ensurePhpIni()` вызывался только внутри `frankenphp.start()`, то есть на шаге 2 супервизора, тогда как миграции идут шагом 1. **На чистом профиле** миграции успевали отработать раньше, чем появлялся php.ini, и весь запуск ложился. На втором и последующих запусках файл уже лежал от прошлого сеанса — поэтому отказ был строго первозапускным и в разработке не воспроизводился.

Гарантия теперь принадлежит супервизору (`native/supervisor/index.js`, до `migrations.run()`). Вызов идемпотентен, и собственный вызов внутри `frankenphp.start()` намеренно оставлен: он держит `start()` самодостаточным для пути перезапуска воркера (`reloadForPlugin`), который через супервизор не проходит.

**Общий признак обоих багов:** они первозапускные и молчаливые. Отсюда правило — проверять приложение на ЧИСТОМ профиле, что и делает гейт релиза (`scripts/release-smoke.js` запускает сборку с `--user-data-dir` во временном каталоге).

## Переопределение `$_SERVER['DATABASE_URL']`/`QUEUE_DATABASE_URL` в Acceptance-тесте молча игнорируется — нужен и `$_ENV`

Паттерн из существующих Acceptance-тестов (`SettingsProxyLocalizationTest`) — переопределить `$_SERVER['APP_RUNTIME_DIR']`/`PLUGINS_DIR`/`PLUGINS_CONFIG_PATH`/`CONFIG_PATH` перед `self::bootKernel()` — работает для этих четырёх переменных ровно потому, что ни у одной нет значения по умолчанию в `.env` (только `%env(default:...:ИМЯ)%`, реальное значение передаёт только Electron в проде). Для `DATABASE_URL`/`QUEUE_DATABASE_URL` он не работает: `.env` задаёт им дефолт, `tests/bootstrap.php`'s `Dotenv::bootEnv()` кладёт этот дефолт в `$_ENV` **один раз на весь процесс PHPUnit**, до `setUp()` любого теста, а `Container::getEnv()` при резолве `%env(...)%` читает `$_ENV` раньше `$_SERVER` — переопределение одного `$_SERVER` тихо проигрывает уже заполненному `$_ENV`, без исключения и без предупреждения, просто DBAL-соединение открывает старый (продовый/дефолтный) путь.

Практическое следствие: тест, которому реально нужна БД через контейнер (а не через отдельно сконструированный `EntityManager` на sqlite-in-memory, как делает `SettingsControllerLocaleSwitchFunctionalTest::createReindexService()`), обязан переопределять **оба** суперглобальных массива:
```php
$_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///'.$tmpPath;
```
Симптом зависит от того, существует ли каталог рабочей БД приложения на момент прогона:
- каталога нет (как сейчас на CI, до миграций) — `PDOException: SQLSTATE[HY000] [14] unable to open database file`, указывающий на `../data/data.db`/`../data/queue.db` (реальный прод-путь), а не на временный файл теста;
- каталог существует (как на машине разработчика после первого запуска приложения, и как будет на CI, если миграции начнут прогоняться перед тестами) — исключения не будет вовсе. `SchemaTool::createSchema()` молча запишет полную схему ORM в рабочую базу приложения: файл вырастает с нуля до ~190 КБ. Проверено экспериментально: если в `SettingsLocaleSwitchAcceptanceTest` убрать присваивания `$_ENV`, оставив только `$_SERVER`, тест **остаётся зелёным** — зелёный прогон здесь ничего не доказывает и не является основанием считать это присваивание лишним или запись устаревшей.

Отдельно: `MESSENGER_TRANSPORT_DSN=doctrine://queue?auto_setup=0` означает, что **любой** вызов `Doctrine\ORM\Tools\SchemaTool::createSchema()` в тесте (не только явный вызов на "queue"-соединении) попутно триггерит `postGenerateSchema` → `MessengerTransportDoctrineSchemaListener`, который открывает DBAL-соединение `queue` для проверки существования messenger-таблицы. Если тест переопределяет только `DATABASE_URL`, но не `QUEUE_DATABASE_URL`, `createSchema()` падает на **queue**-соединении раньше, чем успевает создать схему для основного.

## `grid-template-columns: repeat(auto-fill, …)` — не менять на `auto-fit`, от этого зависит расчёт лимита каталога

`app/assets/js/anime-list.js` (issue #665) вычисляет размер страницы каталога по фактическому числу колонок сетки: `getComputedStyle(grid).gridTemplateColumns` возвращает разрешённый список треков в пикселях (`"182.4px 182.4px …"`), и число колонок — это длина этого списка. Приём работает **только** благодаря `auto-fill` в `app/assets/scss/_anime-list.scss`: он создаёт треки по ширине контейнера независимо от количества детей, поэтому измерение верно даже на пустой сетке (первый запрос страницы происходит до того, как в сетке появилась хоть одна карточка).

`auto-fit` — не синоним: он схлопывает пустые треки до `0px` (спецификация CSS Grid, §12.7.1 "Empty tracks"), поэтому на пустой сетке `gridTemplateColumns` вернул бы один трек (или отличное от реального число колонок), и вычисленный лимит перестал бы быть кратным фактическому числу колонок — вернулся бы дефект v1 (неполная последняя строка, которую нельзя отличить от конца каталога). Замена `auto-fill` → `auto-fit` в `_anime-list.scss` **молча** ломает расчёт лимита: раскладка визуально не меняется (оба значения одинаково растягивают заполненные треки на `1fr`), различие проявляется только на пустой/частично заполненной сетке, где обычный ручной просмотр страницы его не поймает.

## Модуль клиентского JS не запускает себя сам — его монтирует реестр контролов (issue #734)

Весь клиентский JS собирается в один `app/public/js/main.js`, который подключён **на каждой
странице**. Поэтому модуль, который на загрузке лезет в DOM (`document.getElementById(...)` в теле
IIFE, как было до #734), в бандле исполняется везде и шарит по чужой разметке.

Правило: файл в `app/assets/js/` на верхнем уровне делает **только** регистрацию —
`window.Controller.registerControl('имя', mountFn)`. Всё остальное живёт внутри `mountFn(root)`,
которую реестр (`app/assets/js/controller.js`) вызывает, встретив в документе элемент с
`data-control="имя"`.

Что из этого следует и ломается неочевидно:

- **Монтирование висит на `htmx:load`, не на `htmx:afterSwap` и не на `DOMContentLoaded`.**
  `afterSwap` срабатывает раньше, чем htmx привязал `hx-*` в новом поддереве, — контрол увидел бы
  наполовину готовый фрагмент. `htmx:load` выстреливает и на вставленном фрагменте, и один раз на
  `<body>` при готовности документа, поэтому отдельный DOM-ready путь не нужен.
- **`mountFn` может вернуть функцию-очистку**, её вызывает `htmx:beforeCleanupElement` при удалении
  узла. Без неё таймеры, подписки и `ResizeObserver`/`IntersectionObserver` переживают своп и
  продолжают писать в отсоединённый DOM. В разметке уже есть область, пересвопывающая себя каждые
  две секунды (`app/templates/settings/market/_refresh_area.html.twig`), — на ней это накапливается
  бесконечно.
- **Повторное монтирование того же узла не происходит** — реестр помечает узел; рассчитывать на
  «меня позовут ещё раз» нельзя.
- **Исключение внутри `mountFn` не роняет остальные контролы**, но и не чинится само: оно уходит в
  `console.error`, а живой прогон (`npm run shots`) считает это отказом.
- **Имя в `data-control` без зарегистрированного контрола** — это только `console.error`, DOM узла
  не трогается и плашка не рисуется. Опечатки в шаблонах приложения ловит
  `ControlNamesAreRegisteredTest`; `data-control` из HTML плагинов намеренно ни на что не влияет.

Не всякий файл в `app/assets/js/` — контрол, и это нормально. Помимо регистрации на верхнем
уровне допустима ровно одна вещь: публикация объекта-неймспейса на `window` без обращения к DOM.
Так устроены четыре хелпера каталога — `anime-list-query.js`, `anime-list-grid.js`,
`anime-list-filters.js`, `anime-list-filter-render.js`: каждый объявляет свой
`window.AnimeList*`, а в DOM лезет только из функций, которые зовёт `mountFn` контрола
`anime-list` из `anime-list.js`. Правило «никакого кода верхнего уровня» — про работу с DOM и
побочные эффекты на загрузке, а не про объявление объекта.

Контролами не являются и в реестр не переводятся: `color-mode.js` и `inline-handlers.js` (обязаны
отработать немедленно — до первой отрисовки и до первой картинки, issues #638 и #633),
`translations.js` и `scan.js` (сервисы без собственного элемента).

## `app/public/js/` — генерируемый каталог, исходники лежат в `app/assets/js/` (issue #735)

Симметрично стилям: исходники в `app/assets/`, выход сборки — в `app/public/`, и выход в
`.gitignore`. В `app/public/js/` после `npm run assets` лежат `main.js` (+ `.map`) и копии
`htmx.min.js`/`bootstrap.bundle.min.js` — всё это генерируется, править там нечего: следующая
сборка затрёт.

Практические следствия:

- `npm run assets` обязателен перед запуском приложения; `npm start` зовёт его сам, `npm run shots`
  падает, если сборки нет.
- Линтер и jest смотрят на `app/assets/js/`; собранный бандл не линтуется и тестами не грузится.
- Вход для esbuild генерируется сборкой (`scripts/build-assets.js`): `controller.js` первым,
  остальные — отсортированно. Реестр обязан исполниться раньше любого `registerControl()`.

