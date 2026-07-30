# CLAUDE.md — anime-db-desktop

Entry point for Claude Code agents. For deeper reference see [`.claude-docs/`](.claude-docs/index.md).

## Документация-индекс

- [.claude-docs/index.md](.claude-docs/index.md) — таблица маршрутизации; начни отсюда
- [.claude-docs/architecture.md](.claude-docs/architecture.md) — монорепо, native/app граница, FrankenPHP, Meilisearch, paths
- [.claude-docs/decisions.md](.claude-docs/decisions.md) — принятые решения с обоснованием
- [.claude-docs/gotchas.md](.claude-docs/gotchas.md) — нетривиальные ловушки
- [.claude-docs/conventions.md](.claude-docs/conventions.md) — стиль кода, форматирование, команды
- [.claude-docs/cookbook.md](.claude-docs/cookbook.md) — рецепты частых задач (settings-страница, entity+миграция, Messenger-job, листенер, реестр плагина)

## О проекте

`anime-db-desktop` — десктопное приложение AnimeDB версии 2 (v2).

- **Стек**: Electron + Symfony + FrankenPHP
- **Статус**: активная разработка, закрытый репозиторий (скрыт до релиза)
- **Лицензия**: GPLv3
- **Ветка по умолчанию**: `master`
- **Архитектурный подход**: Domain-Driven Design (DDD) — сущности проектируются как богатая доменная модель (rich domain model), бизнес-правила и инварианты живут в самих сущностях, а не в сервисах-обвязке поверх анемичных структур данных. **Любое архитектурное решение (новые сущности, границы модулей, распределение полей/методов между классами) должно соответствовать принципам DDD**, не просто «лишь бы скомпилировалось».

## Структура репозитория

```
anime-db-desktop/
├── native/      # Electron: OS-интеграция, процессы, окна, трей, lifecycle
│   ├── index.js
│   ├── paths.js
│   ├── supervisor/
│   │   ├── index.js, frankenphp.js, meilisearch.js, port.js, healthcheck.js
│   ├── window/index.js
│   ├── tray/index.js
│   └── lifecycle/index.js
├── app/         # Symfony: бизнес-логика, HTTP, шаблоны, сущности, плагины
│   ├── bin/console
│   ├── config/
│   ├── migrations/
│   ├── public/index.php       # точка входа FrankenPHP (worker mode)
│   ├── src/
│   │   ├── Controller/
│   │   │   └── HealthController.php  # GET /health → 200 OK
│   │   ├── Entity/
│   │   ├── Repository/
│   │   ├── Plugin/            # плагинная система
│   │   └── Kernel.php
│   ├── templates/             # Twig + HTMX-фрагменты
│   ├── var/                   # в продакшн → AppData/AnimeDB/var/ (APP_RUNTIME_DIR)
│   └── composer.json
├── bin/         # .gitignore — тянутся download-bins.js при сборке
│   ├── frankenphp/frankenphp.exe   # PHP 8.5 встроен
│   ├── meilisearch/meilisearch.exe
│   └── php/php.ini.template        # под git; динамический ini пишется в AppData
├── scripts/
│   ├── download-bins.js            # качает бинарники под нужную архитектуру
│   └── build.js
└── package.json                    # корневой, electron-builder
```

**Граница `native/` ↔ `app/`**: `native/` не знает про бизнес-логику. Подробно: [`notes/desktop_native_layer.md`](../../notes/desktop_native_layer.md) в воркспейсе.

**Сборка**: только x64 (FrankenPHP не имеет x32-сборки для Windows).

## Команды

Из `app/` (если не указано иное):

```bash
vendor/bin/phpunit                              # весь набор тестов
vendor/bin/phpunit --filter <TestClassOrMethod> # один тест (при итерации)
vendor/bin/phpunit tests/Unit/Path/SomeTest.php # один файл (при итерации)
composer phpstan                                # PHPStan level 8
composer cs-check   # / cs-fix                  # php-cs-fixer (проверка / автофикс)
```

JS (из корня репозитория): `npm run lint` / `npm run lint:fix`.

### Верификация при работе (важно для стоимости прогона)

Во время итерации гоняй **только затронутые тесты** (`--filter` или путь к файлу), а **полный `vendor/bin/phpunit` + `composer phpstan` — один раз перед коммитом** (обязательно: до коммита рабочее дерево должно быть зелёным по полному набору). Полный прогон 600+ тестов на каждой мелкой правке дорог и не нужен — CI на PR всё равно прогоняет весь набор. Детали и обоснование — [.claude-docs/conventions.md](.claude-docs/conventions.md#верификация-при-итерации).

## Границы

### MUST
- Лицензионная шапка — в **каждом** исходном файле (PHP, JS, TS, Bash и т.д.); шаблоны и правило диапазона дат — [.claude-docs/license-header.md](.claude-docs/license-header.md)
- Документация — на русском языке (en-версии — исключение)
- Таблицы в md-файлах: ячейки выровнены пробелами, `|` вертикально совпадают
- **Весь текст в Twig-шаблонах — только через `{% trans %}` / `trans()`** (домен `messages`, каталоги `app/translations/messages.{locale}.yaml`). Захардкоженный текстовый узел в шаблоне не переводится и не попадёт в ru/en словарь.

### MUST NOT
- Не добавлять сторонний код или зависимости без проверки совместимости с GPLv3
- **Не использовать Yoda-style условия** (`null === $x`, `5 === $count`) — только обычный порядок (`$x === null`, `$count === 5`). Правило `yoda_style` в `app/.php-cs-fixer.dist.php` настроено массивом-конфигом (`['equal' => false, 'identical' => false, 'less_and_greater' => false]`), поэтому **фиксер автоматически переписывает Yoda в обычный порядок**, а CI-джоба `cs-check` (dry-run) падает на любом Yoda-условии. `composer cs-fix` чинит стиль сам. Раньше стояло `yoda_style => false`, что лишь ВЫКЛЮЧАЛО фиксер и ничего не навязывало — отсюда постоянные рецидивы (в т.ч. от openronin, который по умолчанию пишет Yoda). Теперь это машинно-проверяемый инвариант, а не предмет ручного ревью.

## Workflow

- Ветка по умолчанию: `master`
- Трекер задач: workspace [`/var/www/anime-db-workspace`](https://github.com/openronin/anime-db-workspace) — `tasks/` и `roadmap/`
- Автоматическое ИИ-ревью каждого PR — `.github/workflows/claude-review.yml` (auth через секрет `CLAUDE_CODE_OAUTH_TOKEN` из подписки Max). Детали и настройка — [.claude-docs/decisions.md](.claude-docs/decisions.md)
