<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
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

declare(strict_types=1);

namespace App\Service\Plugin\Exception;

use AnimeDb\PluginContracts\Manifest\InvalidManifestException;
use AnimeDb\PluginContracts\Manifest\InvalidManifestJsonException;
use AnimeDb\PluginContracts\Manifest\ManifestValidationError;

/**
 * Thrown by {@see \App\Service\Plugin\InstalledPluginsRegistry::reconcile()} for a single plugin
 * directory whose `manifest.json` is missing or fails to parse. Wraps the contract package's own
 * {@see InvalidManifestException}/{@see InvalidManifestJsonException} so callers only need to
 * know about one exception type for "this plugin directory could not be reconciled", while still
 * keeping the structured {@see ManifestValidationError} list (empty for a missing file or a JSON
 * syntax error, populated for a content validation failure) for structured logging.
 */
final class InvalidInstalledPluginException extends \RuntimeException
{
    /**
     * @param ManifestValidationError[] $errors
     */
    public function __construct(
        public readonly string $pluginDir,
        public readonly array $errors,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf('Invalid or missing manifest.json in plugin directory "%s".', $pluginDir),
            previous: $previous,
        );
    }
}
