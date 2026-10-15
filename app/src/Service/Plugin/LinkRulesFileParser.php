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

namespace App\Service\Plugin;

use AnimeDb\PluginContracts\LinkRules\LinkRulesValidator;
use AnimeDb\PluginContracts\Manifest\ManifestValidationError;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Turns the text of a plugin's `link-rules.yaml` into an ordered `expression => replacement` map.
 * The manifest validator does not reject unknown fields, so the file gets its own check here: a
 * file that does not parse or does not pass {@see LinkRulesValidator} is rejected as a whole.
 */
final class LinkRulesFileParser
{
    public const FILE_NAME = 'link-rules.yaml';

    public function __construct(private readonly LinkRulesValidator $validator = new LinkRulesValidator())
    {
    }

    /**
     * @return array<string, string>
     *
     * @throws \InvalidArgumentException when the text is not valid rules; the message lists the reasons
     */
    public function parse(string $contents): array
    {
        try {
            $data = Yaml::parse($contents);
        } catch (ParseException $exception) {
            throw new \InvalidArgumentException('Not a valid YAML: '.$exception->getMessage(), 0, $exception);
        }

        if (!\is_array($data)) {
            throw new \InvalidArgumentException('The file must contain a map with "version" and "rules".');
        }

        $errors = $this->validator->validate($data);
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode('; ', array_map(static fn (ManifestValidationError $error): string => $error->field.': '.$error->message, $errors)));
        }

        /** @var array<string, string> $rules validated above */
        $rules = $data['rules'];

        return $rules;
    }
}
