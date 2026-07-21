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
- **FrankenPHP** — Go-бинарник со встроенным PHP 8.5 (worker mode). Отдельный
  PHP-рантайм устанавливать не нужно — он вкомпилирован.
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

GPL-3.0-or-later. См. [LICENSE](LICENSE).
