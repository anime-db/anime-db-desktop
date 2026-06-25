# CLAUDE.md — anime-db-desktop

Entry point for Claude Code agents. For deeper reference see [`.claude-docs/`](.claude-docs/index.md) (создаётся по мере наполнения).

## О проекте

`anime-db-desktop` — десктопное приложение AnimeDB версии 2 (v2).

- **Стек**: Electron + Symfony + FrankenPHP
- **Статус**: активная разработка, закрытый репозиторий (скрыт до релиза)
- **Лицензия**: GPLv3
- **Ветка по умолчанию**: `master`

## Команды

```bash
# TODO: заполнить по мере настройки окружения
```

## Лицензионная шапка файлов (обязательно)

**Каждый исходный файл должен начинаться с лицензионной шапки.** Формат зависит от языка.

### PHP

```php
/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) <год создания файла>-<год последнего изменения>, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
 */

/*
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://gnu.org>.
 */
```

### JavaScript / TypeScript

```js
/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) <год создания файла>-<год последнего изменения>, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://gnu.org>.
 */
```

### Bash / Shell

```bash
# AnimeDb package.
#
# @author    Peter Gribanov <info@peter-gribanov.ru>
# @copyright Copyright (c) <год создания файла>-<год последнего изменения>, Peter Gribanov
# @license   https://gnu.org GPL-3.0-or-later
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program. If not, see <https://gnu.org>.
```

### Правила диапазона дат в `@copyright`

- Диапазон: от года создания файла до года его последнего изменения.
- Если файл менялся только в один год — без диапазона: `Copyright (c) 2026, Peter Gribanov`.
- Год создания проекта или текущий год не указывается, если файл тогда не менялся.

## Границы

### MUST
- Лицензионная шапка — в **каждом** исходном файле (PHP, JS, TS, Bash и т.д.)
- Документация — на русском языке (en-версии — исключение)
- Таблицы в md-файлах: ячейки выровнены пробелами, `|` вертикально совпадают

### MUST NOT
- Не добавлять сторонний код или зависимости без проверки совместимости с GPLv3

## Workflow

- Ветка по умолчанию: `master`
- Трекер задач: workspace [`/var/www/anime-db-workspace`](https://github.com/openronin/anime-db-workspace) — `tasks/` и `roadmap/`
