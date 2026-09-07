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
 * Router for `php -S`, used only by `npm run shots` (scripts/shots/run.js) to serve app/public
 * for the screenshot pipeline. The packaged application never runs through `php -S` — it is
 * served by FrankenPHP via app/Caddyfile — so this file has no bearing on production behaviour.
 *
 * `php -S ... public/index.php` (passing the front controller itself as the router) cannot be
 * used here: PHP's built-in server sets $_SERVER['SCRIPT_FILENAME'] to the *requested* path for
 * every request, including static assets, so a request for e.g. `css/app.css` would run
 * public/index.php with SCRIPT_FILENAME pointing at the CSS file. Symfony Runtime resolves the
 * front controller from that variable and fails, and the request comes back as a 500. This
 * router runs for every request instead and decides itself whether to serve a file under
 * public/ as-is or hand off to the front controller.
 */

$path = urldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = $_SERVER['DOCUMENT_ROOT'].$path;

if ($path !== '/' && is_file($file)) {
    // Returning false tells the built-in server to serve $file itself (correct Content-Type,
    // correct status code) instead of running any PHP.
    return false;
}

require $_SERVER['DOCUMENT_ROOT'].'/index.php';
