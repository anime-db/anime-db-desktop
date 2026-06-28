---
tags: [memory/repo, conventions]
---

# Конвенции — anime-db-desktop

## Стиль PHP-кода

**Инструмент**: `friendsofphp/php-cs-fixer` ^3.0, конфиг: `app/.php-cs-fixer.dist.php`.

**Rule Set**: `@Symfony` с переопределениями:
- `declare_strict_types => true` — обязателен во всех PHP-файлах
- `yoda_style => false` — обычный порядок операндов (`$x === null`, не `null === $x`)
- `setRiskyAllowed(true)` — разрешены risky-правила Symfony (нужны для `declare_strict_types`)

**Команды** (запускать из `app/`):
```bash
composer cs-fix    # применить автоисправления
composer cs-check  # проверить без изменений (dry-run + diff)
```

**Finder сканирует**: `app/src/` и `app/tests/`.

## Лицензионные шапки

Каждый PHP-файл начинается с двухчастной лицензионной шапки:
1. `/** @author ... @copyright ... @license ... */` (docblock)
2. `/* This program is free software... */` (GPL-текст)

Далее — `declare(strict_types=1);`.

Диапазон в `@copyright`: год создания файла — год последнего изменения. Один год — без диапазона.

## Стиль JS-кода (native/)

**Инструмент**: `eslint` ^9, конфиг: `eslint.config.js` (flat config).

**Конфигурация**: `eslint:recommended`, среда `node`, `sourceType: "commonjs"` (CommonJS).

**Область**: только `native/**/*.js`. Директории `scripts/` и `tests/` не включены.

**Команды** (запускать из корня репозитория):
```bash
npm run lint      # проверить без изменений
npm run lint:fix  # применить автоисправления
```

## Git / коммиты

Сообщения коммитов — на английском языке.
