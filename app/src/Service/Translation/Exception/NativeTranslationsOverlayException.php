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

namespace App\Service\Translation\Exception;

/**
 * Thrown by {@see \App\Service\Translation\NativeTranslationsOverlayWriter::write()} for either of
 * its two fatal failure classes — an unreadable/invalid reference catalog, or a failure to write,
 * rename or remove an overlay file — never for a single plugin's broken `translations/native/`
 * directory, which is logged and skipped instead (see the class docblock). Both fatal classes are
 * meant to propagate out of {@see \App\Service\Plugin\InstalledPluginsRegistry::reconcile()} and
 * roll back the install/update/remove operation that triggered it; callers do not need to tell the
 * two apart, since the recovery is the same either way (the operation fails, nothing here retries).
 */
final class NativeTranslationsOverlayException extends \RuntimeException
{
}
