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

namespace App\Service\Market\Exception;

/**
 * Thrown by {@see \App\Service\Market\PluginRegistryLoader::load()} when the downloaded
 * `plugins-registry.json` does not carry a valid detached Ed25519 signature from any of the
 * trusted public keys — either the signature is malformed, or it was produced by a key the
 * client does not accept (see {@see \App\Service\Market\PluginRegistrySignatureVerifier}). The
 * registry's content is never parsed once this is thrown: signature verification runs first,
 * over the exact bytes as downloaded.
 */
final class InvalidPluginRegistrySignatureException extends \RuntimeException
{
}
