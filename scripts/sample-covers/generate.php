<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
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
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

/*
 * Разовая генерация обложек-заглушек для демо-данных (`app/public/sample/`).
 *
 * Это НЕ рантайм-код: приложение его не вызывает, в дистрибутив он не попадает
 * (см. `build.files` в package.json). Результат работы — семь файлов в
 * `app/public/sample/`, которые лежат в репозитории; скрипт хранится рядом с
 * ними как документация происхождения и способ пересобрать их при изменении
 * набора демо-тайтлов.
 *
 * Зачем вообще заглушки. Настоящие обложки аниме в дистрибутив не поставляются
 * принципиально: это чужие произведения, и распространять их внутри инсталлятора
 * нельзя — тем более под GPL, которая обязывает передавать получателю права,
 * которых у нас на них нет. Обложки реальных тайтлов попадают в каталог
 * пользователя штатным путём, из плагина, по его собственному действию. Без
 * заглушек демо-каталог выглядит сеткой пустых прямоугольников, поэтому здесь
 * лежат абстрактные изображения, права на которые принадлежат проекту.
 *
 * Почему абстракция без текста. Рисовать на обложке название означало бы тащить
 * в генерацию шрифт с покрытием кириллицы и CJK (иначе японские названия
 * превращаются в квадраты) — мегабайты и лишняя лицензионная сущность. Плюс
 * название и так стоит подписью под карточкой.
 *
 * Параметры изображения выбраны не произвольно:
 *
 * - 700x990 — то, что реально отдаёт Shikimori в `poster.originalUrl` (замерено);
 *   демо-обложки должны быть похожи на то, что окажется в каталоге у пользователя;
 * - WebP, качество 82 — как в `App\Service\Media\ImageNormalizer`, через который
 *   проходят настоящие обложки; формат единственно возможный, потому что протокол
 *   `app-media://` отдаёт Content-Type по whitelist из одной записи `.webp`
 *   (`native/protocols/app-media.js`);
 * - тона в коридоре 10-28° по HSL — это брендовый диапазон между `#db3400` (14°)
 *   и `#fc6703` (24°). Более широкий коридор уводит часть обложек в оливковое.
 *   Обложки различаются между собой не оттенком, а светлотой фона и плотностью
 *   пятен: семь одинаково ярких оранжевых плиток сливались бы в одну.
 *
 * Генерация детерминирована: цвет и раскладка выводятся из md5 названия, поэтому
 * повторный запуск даёт те же файлы, а новый демо-тайтл получает свою обложку
 * автоматически.
 *
 * Запуск: php scripts/sample-covers/generate.php
 */

/** Демо-тайтлы: slug (имя файла) => название (источник детерминированного зерна). */
const TITLES = [
    'fullmetal-alchemist-brotherhood' => 'Fullmetal Alchemist: Brotherhood',
    'spirited-away' => 'Spirited Away',
    'gintama' => 'Gintama',
    'hellsing-ultimate' => 'Hellsing Ultimate',
    'sousou-no-frieren' => 'Sousou no Frieren',
    'one-punch-man' => 'One Punch Man',
    'solo-leveling' => 'Solo Leveling',
];

const WIDTH = 700;
const HEIGHT = 990;
const WEBP_QUALITY = 82;

/** Брендовый коридор тонов: #db3400 — 14°, #fc6703 — 24°. */
const HUE_MIN = 10;
const HUE_MAX = 28;

/** Светлота фона: четыре ступени, чтобы обложки различались между собой. */
const BACKGROUND_LIGHTNESS = [0.10, 0.14, 0.18, 0.22];

const SPOTS_PER_COVER = 26;

/**
 * @return array{0: int, 1: int, 2: int}
 */
function hslToRgb(float $hue, float $saturation, float $lightness): array
{
    $c = (1 - abs(2 * $lightness - 1)) * $saturation;
    $x = $c * (1 - abs(fmod($hue / 60, 2) - 1));
    $m = $lightness - $c / 2;

    [$r, $g, $b] = match (true) {
        $hue < 60 => [$c, $x, 0],
        $hue < 120 => [$x, $c, 0],
        $hue < 180 => [0, $c, $x],
        $hue < 240 => [0, $x, $c],
        $hue < 300 => [$x, 0, $c],
        default => [$c, 0, $x],
    };

    return [
        (int) round(($r + $m) * 255),
        (int) round(($g + $m) * 255),
        (int) round(($b + $m) * 255),
    ];
}

function generateCover(string $title, string $outputPath): void
{
    $seed = (int) hexdec(substr(md5($title), 0, 8));
    mt_srand($seed);

    $hue = HUE_MIN + $seed % (HUE_MAX - HUE_MIN + 1);
    $lightness = BACKGROUND_LIGHTNESS[$seed % \count(BACKGROUND_LIGHTNESS)];

    $image = imagecreatetruecolor(WIDTH, HEIGHT);
    if ($image === false) {
        throw new \RuntimeException("Не удалось создать холст для «{$title}».");
    }
    imagealphablending($image, true);

    [$r, $g, $b] = hslToRgb($hue, 0.82, $lightness);
    imagefilledrectangle($image, 0, 0, WIDTH, HEIGHT, (int) imagecolorallocate($image, $r, $g, $b));

    for ($i = 0; $i < SPOTS_PER_COVER; ++$i) {
        $spotHue = max(6, min(34, $hue + mt_rand(-6, 10)));
        [$sr, $sg, $sb] = hslToRgb((float) $spotHue, mt_rand(88, 100) / 100, mt_rand(44, 62) / 100);
        $color = (int) imagecolorallocatealpha($image, $sr, $sg, $sb, mt_rand(45, 105));
        $diameter = mt_rand(90, 420);
        imagefilledellipse($image, mt_rand(0, WIDTH), mt_rand(0, HEIGHT), $diameter, $diameter, $color);
    }

    imagewebp($image, $outputPath, WEBP_QUALITY);
    imagedestroy($image);
}

$outputDir = \dirname(__DIR__, 2).'/app/public/sample';
if (!is_dir($outputDir)) {
    throw new \RuntimeException("Каталог {$outputDir} не найден.");
}

foreach (TITLES as $slug => $title) {
    $path = $outputDir.'/'.$slug.'.webp';
    generateCover($title, $path);
    printf("%s — %d КБ\n", basename($path), (int) round((int) filesize($path) / 1024));
}
