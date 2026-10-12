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
 * A notification to record: the kind, the snapshot of its parameters, the subject for the link and
 * the merge key. Sources build one only through the typed static factory method of the
 * kind's class (see {@see Kind\NotificationKindInterface}) and never assemble the `params` array
 * by hand; this class holds no kind-specific methods.
 *
 * `params` holds JSON scalars only (string/int/bool/null): dates, enums and objects do not belong
 * there, the time of the event is the `occurredAt` column.
 */
class NotificationDraft
{
    /**
     * @internal for the factory methods of the kind classes only
     *
     * @param array<string, string|int|bool|null> $params
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $params,
        public readonly ?string $subjectType = null,
        public readonly ?string $subjectId = null,
        public readonly ?string $dedupKey = null,
    ) {
        self::assertScalars($params);
        if (($subjectType === null) !== ($subjectId === null)) {
            throw new \InvalidArgumentException('A subject needs both a type and an id.');
        }
    }

    /** @return array{type: string, id: string}|null */
    public function subject(): ?array
    {
        return $this->subjectType !== null && $this->subjectId !== null
            ? ['type' => $this->subjectType, 'id' => $this->subjectId]
            : null;
    }

    /**
     * @param array<mixed> $params
     */
    private static function assertScalars(array $params): void
    {
        foreach ($params as $name => $value) {
            if (!\is_string($name) || !(\is_scalar($value) || $value === null) || \is_float($value)) {
                throw new \InvalidArgumentException(\sprintf('Notification parameter "%s" must be a string, int, bool or null.', $name));
            }
        }
    }
}
