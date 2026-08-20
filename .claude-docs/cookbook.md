# Cookbook — рецепты частых задач

Пошаговые карты для повторяющихся форм задач: **какие файлы тронуть и какой образец скопировать**, чтобы не искать паттерн с нуля. Каждый рецепт указывает на реальный референс в репозитории — открой его и следуй той же структуре.

Общее для всех рецептов:
- Лицензионная шапка — в **каждом** новом файле ([license-header.md](license-header.md)).
- Не Yoda-style (`$x === null`, не `null === $x`) — фиксер правит это автоматически ([conventions.md](conventions.md)).
- Богатая доменная модель (DDD): инварианты в сущностях, не в анемичных структурах.
- Верификация — точечные тесты при итерации, полный прогон один раз перед коммитом ([conventions.md](conventions.md#верификация-при-итерации)).

---

## Страница настроек (список + действие)

**Образец:** `SyncReviewController` (или `LabelController`) — `Controller/Settings/`.

1. Контроллер `app/src/Controller/Settings/<Name>Controller.php`:
   - `GET /settings/<name>` — рендер списка (данные из сервиса/репозитория) через `Twig\Environment`.
   - `POST /settings/<name>/{id}/<action>` — действие с проверкой CSRF (`CsrfTokenManagerInterface`, id токена `<name>_<action>_<id>`), затем PRG-редирект. Сущность из `{id}` — через ParamConverter (авто-404).
2. Шаблон `app/templates/settings/<name>/index.html.twig` — список + пустое состояние + форма `POST` со скрытым `_token` (`csrf_token(...)`).
3. Ссылка на страницу — в `app/templates/settings/index.html.twig`.
4. **Переводы** — ключи `settings_<name>.*` в `app/translations/messages.ru.yaml` **и** `messages.en.yaml` (паритет обязателен, проверяется полным прогоном PHPUnit — `CatalogTranslationsTest`). Весь текст шаблона — только через `trans`. Конвенции по плейсхолдерам и плюрализации — [conventions.md](conventions.md#локализация-переводы).
5. Тест `app/tests/Unit/Controller/Settings/<Name>ControllerTest.php` по образцу `SyncReviewControllerTest` (список, успешное действие, отказ при неверном CSRF).

## Doctrine-сущность + миграция

**Образец:** `SyncReviewItem` + `Version20260719000000` + `SyncReviewItemRepository` + `SyncReviewService`.

1. Сущность `app/src/Entity/<Name>.php` — даты через кастомный тип `unix_timestamp` (как везде в проекте), мутации через методы (инкапсуляция). Enum-поля — `enumType:`.
2. Enum (если нужен) — `app/src/Entity/Enum/<Name>.php` (backed string). **Расширяемый enum — БЕЗ `CHECK`-ограничения в миграции** (SQLite не `ALTER`-ит CHECK → table-rebuild; `enumType` валидирует на уровне app). См. [gotchas.md](gotchas.md).
3. Миграция `app/migrations/Version<UTC-timestamp>.php` в стиле соседних (`CREATE TABLE`, индексы; проверь `up()`/`down()`).
4. Репозиторий `app/src/Repository/<Name>Repository.php` (+ тонкий `Service/` фасад при необходимости).
5. Тест на **реальном in-memory SQLite** через `SchemaTool` — образец `SyncReviewItemRepositoryTest`.

## Фоновая задача (Messenger, async)

**Образец:** `BackfillExternalIdMessage` + `BackfillExternalIdMessageHandler`; для простых — `IndexAnimeMessage`.

1. Сообщение `app/src/Message/<Name>Message.php` — иммутабельный DTO с полями (id как строка/скаляр).
2. Обработчик `app/src/MessageHandler/<Name>MessageHandler.php` с атрибутом `#[AsMessageHandler]`.
   - **Ошибки не глотать** try/catch — ретрай отдан транспорту `async` (`retry_strategy`), финальный провал логируется Messenger'ом. Образец — `IndexAnimeMessageHandler`.
   - Долгая задача по всей БД — постранично (`Paginator`, `flush()`+`clear()` между страницами) + `App\Service\JobLock\JobLockService` (`acquire`/`heartbeat`, ключ `<job>:<id>`) чтобы не параллелить. Образец — `ScanStorageMessageHandler` / `BackfillExternalIdMessageHandler`.
3. **Зароутить** сообщение на `async` в `app/config/packages/messenger.yaml`, блок `routing:` (рядом с `App\Message\*: async`).
4. Тест обработчика — `tests/Unit/MessageHandler/`.

## Doctrine-листенер → диспатч сообщения

**Образец:** `AnimeSearchIndexListener` (`postPersist`/`postUpdate`, реакция на любой insert/update), `DomainEventListener` (тот же хук, но диспатчит не `Message`, а доменные события, накопленные агрегатом через `AggregateRootInterface`/`AggregateRootTrait::releaseEvents()` — см. [sync.md](sync.md)).

1. Листенер `app/src/EventListener/<Name>Listener.php` с `#[AsDoctrineListener(event: Events::...)]`.
   - Реакция на изменение **конкретного поля** — `preUpdate` + `PreUpdateEventArgs::hasChangedField('...')` (единственный event с changeset). Реакция на любой insert/update — `postPersist`/`postUpdate`.
2. **В листенере — только диспатч `Message` в Messenger, НЕ тяжёлая работа** (сеть/долгие операции). Сам `push()`/индексация — в async-обработчике.
   - Гоча: диспатч из flush-события безопасен, пока транспорт `async` — doctrine (транзакционный, откат атомарен). Не роутить такие сообщения на sync/нетранзакционный транспорт.
3. Тест — `tests/Unit/EventListener/`.

## Реестр плагин-возможности (тег `_instanceof`)

**Образец:** `FillerRegistry` (тег `app.filler`), `SyncRegistry` (`app.sync`), виджет-реестры.

1. Контрактный интерфейс из `anime-db/plugin-contracts` **нельзя** пометить `#[AutoconfigureTag]` (read-only пакет) → тегируется в `app/config/services.yaml`, блок `_instanceof:` (рядом с `app.filler`/`app.sync`/…).
2. Реестр `app/src/Service/Plugin/<Name>Registry.php` — инъекция `iterable` через `#[AutowireIterator('app.<tag>', indexAttribute: 'id')]` (ключ = id DI-сервиса плагина = `PluginId`).
3. Фильтр активности — `features.<capability>` через `PluginsConfigStore::getPluginSettings()`. **Дефолт:** filler — `?? true`; sync/виджеты (приватность/opt-in) — `?? false`.
4. Тест — `tests/Unit/Service/Plugin/`.
