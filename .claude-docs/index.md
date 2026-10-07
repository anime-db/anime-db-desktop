---
tags: [memory/repo, index]
---

# .claude-docs — таблица маршрутизации

Читай только то, что нужно для задачи. Все файлы — русскоязычные.

| Задача                                              | Файл                                                   |
|-----------------------------------------------------|--------------------------------------------------------|
| Понять структуру монорепо и границу native/app      | [architecture.md](architecture.md)                     |
| Понять FrankenPHP, php.ini, Caddyfile               | [architecture.md](architecture.md) §FrankenPHP         |
| Понять Meilisearch — бинарник, индекс, лицензия     | [architecture.md](architecture.md) §Meilisearch        |
| Узнать, почему принято то или иное решение          | [decisions.md](decisions.md)                           |
| Работать с синхронизацией списков (реконсиляция)    | [sync.md](sync.md)                                     |
| Понять подводные камни синхронизации перед правкой  | [sync.md](sync.md) §Реестр подводных камней            |
| Столкнулся с неочевидным поведением / ловушкой      | [gotchas.md](gotchas.md)                               |
| Писать или править клиентский JS (контролы, бандл)  | [gotchas.md](gotchas.md) §Реестр контролов             |
| Понять сборку фронтенда и почему бандл в `<head>`   | [decisions.md](decisions.md) §Клиентский JS            |
| Понять живой прогон приложения и гейт в CI          | [decisions.md](decisions.md) §Живой прогон             |
| Понять E2E-каркас на Playwright (`scripts/e2e/`)    | [decisions.md](decisions.md) §E2E на Playwright        |
| Работать с supervisor (FrankenPHP/Meilisearch)      | [architecture.md](architecture.md) §native/supervisor  |
| Добавить плагин или работать с Plugin/              | [architecture.md](architecture.md) §app/               |
| Понять обновление приложения и Meilisearch-миграцию | [decisions.md](decisions.md) §Обновление               |
| Стиль PHP-кода, форматирование, php-cs-fixer        | [conventions.md](conventions.md)                       |
| Правка вилки `anime-db/plugin-contracts`            | [conventions.md](conventions.md) §Вилка                |
| Конвенции переводов (плейсхолдеры, плюрализация)    | [conventions.md](conventions.md) §Локализация          |
| Лицензионная шапка файла — шаблон, диапазон дат     | [license-header.md](license-header.md)                 |
| Сделать типовую задачу по образцу — рецепты         | [cookbook.md](cookbook.md)                             |
| Добавить settings-страницу / entity+миграцию        | [cookbook.md](cookbook.md)                             |
| Добавить Messenger-job / Doctrine-листенер          | [cookbook.md](cookbook.md)                             |
| Реестр плагин-возможности (тег _instanceof)         | [cookbook.md](cookbook.md)                             |
