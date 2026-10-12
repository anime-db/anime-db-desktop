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

namespace App\Service\Notification;

/**
 * Definition of one notification kind: severity, merge policy, declared parameters and a sample
 * draft built through the kind's `NotificationDraft` factory method (the renderability gate
 * compares it with the latest golden fixture of the kind).
 *
 * Kinds are never removed: a retired kind stays here, without a source, together with its
 * translations, because records of it may still be stored.
 */
final readonly class NotificationKindDefinition
{
    /**
     * @param array<string, NotificationParam> $params
     * @param \Closure(): NotificationDraft    $sample
     */
    public function __construct(
        public string $kind,
        public NotificationSeverity $severity,
        public NotificationMergePolicy $policy,
        public array $params,
        public \Closure $sample,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $kind) !== 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid notification kind "%s".', $kind));
        }
    }

    public function titleKey(): string
    {
        return \sprintf('notification.%s.title', $this->kind);
    }

    public function bodyKey(): string
    {
        return \sprintf('notification.%s.body', $this->kind);
    }

    public function codeKey(string $param, string $code): string
    {
        return \sprintf('notification.%s.%s.%s', $this->kind, $param, $code);
    }

    /**
     * Every translation key a record of this kind can use, over the closed sets of codes.
     *
     * @return list<string>
     */
    public function translationKeys(): array
    {
        $keys = [$this->titleKey(), $this->bodyKey()];
        foreach ($this->params as $name => $param) {
            foreach ($param->codes as $code) {
                $keys[] = $this->codeKey($name, $code);
            }
        }

        return $keys;
    }

    /**
     * The declared parameters of a stored record: stored values win, a declared default fills the
     * gaps, anything undeclared is dropped.
     *
     * @param array<string, mixed> $stored
     *
     * @return array<string, string|int|bool|null>
     */
    public function resolveParams(array $stored): array
    {
        $resolved = [];
        foreach ($this->params as $name => $param) {
            if (\array_key_exists($name, $stored) && $param->accepts($stored[$name])) {
                $resolved[$name] = $stored[$name];
            } elseif ($param->hasDefault) {
                $resolved[$name] = $param->default;
            }
        }

        return $resolved;
    }
}
