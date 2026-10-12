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

use App\Service\WsPublisher;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * The single entry point for writing a notification (issue #1023): merges it by its policy,
 * applies retention, and tells connected clients with `notification.created`.
 */
class NotificationCenter
{
    public const READ_RETENTION_DAYS = 30;
    public const MAX_RECORDS = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly NotificationKindRegistry $registry,
        private readonly NotificationRenderer $renderer,
        private readonly WsPublisher $wsPublisher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Records the notification. Never throws: any failure (storage busy, render, WebSocket
     * publication) is logged and swallowed, so a notification can never change the outcome of the
     * operation that raised it.
     *
     * Call it only after the surrounding flush/commit. It must NOT be called from
     * `DomainEventListener` (that dispatches domain events inside a still open transaction and
     * forbids its subscribers to send a notification) nor from code inside `wrapInTransaction`:
     * a rollback would then leave a notification about something that did not happen.
     */
    public function record(NotificationDraft $draft): void
    {
        try {
            $definition = $this->registry->tryFrom($draft->kind);
            if ($definition === null) {
                throw new \LogicException(\sprintf('Notification kind "%s" has no definition.', $draft->kind));
            }
            if ($definition->policy->requiresKey() && ($draft->dedupKey === null || $draft->dedupKey === '')) {
                throw new \LogicException(\sprintf('Notification kind "%s" needs a dedup key.', $draft->kind));
            }

            $now = new \DateTimeImmutable();
            $written = $this->write($definition, $draft, $now->getTimestamp());
        } catch (\Throwable $e) {
            $this->logger->error('Recording a notification failed.', ['kind' => $draft->kind, 'exception' => $e]);

            return;
        }

        if ($written === null) {
            return;
        }

        try {
            $this->applyRetention($now->getTimestamp());
        } catch (\Throwable $e) {
            $this->logger->error('Notification retention failed.', ['exception' => $e]);
        }

        try {
            $rendered = $this->renderer->render($draft->kind, $draft->params, $draft->subjectType, $draft->subjectId, $now);
            $this->wsPublisher->publish('notification.created', [
                'id' => $written['id'],
                'occurrences' => $written['occurrences'],
                'kind' => $draft->kind,
                'severity' => $definition->severity->value,
                'title' => $rendered->title,
                'body' => $rendered->body,
                'url' => $rendered->url,
                'unread_count' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM notification WHERE read_at IS NULL'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Publishing notification.created failed.', ['kind' => $draft->kind, 'exception' => $e]);
        }
    }

    /**
     * One atomic statement: an unread record with the same key (the partial unique index) absorbs
     * the event, otherwise a new record is inserted. A `once` key is claimed in the same
     * transaction, so a failed insert releases it again.
     *
     * @return array{id: int, occurrences: int}|null null when a `once` key has already fired
     */
    private function write(NotificationKindDefinition $definition, NotificationDraft $draft, int $now): ?array
    {
        return $this->connection->transactional(function () use ($definition, $draft, $now): ?array {
            if ($definition->policy === NotificationMergePolicy::Once) {
                $claimed = $this->connection->executeStatement(
                    'INSERT OR IGNORE INTO notification_once_key (dedup_key, created_at) VALUES (?, ?)',
                    [$draft->dedupKey, $now],
                );
                if ($claimed === 0) {
                    return null;
                }
            }

            $row = $this->connection->fetchAssociative(
                'INSERT INTO notification (kind, severity, params, subject_type, subject_id, occurred_at, read_at, occurrences, dedup_key)
                 VALUES (:kind, :severity, :params, :subject_type, :subject_id, :occurred_at, NULL, 1, :dedup_key)
                 ON CONFLICT (dedup_key) WHERE read_at IS NULL DO UPDATE SET
                     params = excluded.params,
                     severity = excluded.severity,
                     subject_type = excluded.subject_type,
                     subject_id = excluded.subject_id,
                     occurred_at = excluded.occurred_at,
                     occurrences = notification.occurrences + 1
                 RETURNING id, occurrences',
                [
                    'kind' => $draft->kind,
                    'severity' => $definition->severity->value,
                    'params' => json_encode($draft->params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'subject_type' => $draft->subjectType,
                    'subject_id' => $draft->subjectId,
                    'occurred_at' => $now,
                    'dedup_key' => $definition->policy === NotificationMergePolicy::Always ? null : $draft->dedupKey,
                ],
            );
            if ($row === false) {
                throw new \RuntimeException('The notification insert returned no row.');
            }

            return ['id' => (int) $row['id'], 'occurrences' => (int) $row['occurrences']];
        });
    }

    /**
     * Read records older than READ_RETENTION_DAYS go; above MAX_RECORDS the oldest read records go
     * first and unread ones only when the unread alone exceed the cap. `notification_once_key` is
     * left alone.
     */
    private function applyRetention(int $now): void
    {
        $this->connection->executeStatement(
            'DELETE FROM notification WHERE read_at IS NOT NULL AND occurred_at < ?',
            [$now - self::READ_RETENTION_DAYS * 86400],
        );

        $excess = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM notification') - self::MAX_RECORDS;
        if ($excess <= 0) {
            return;
        }

        $this->connection->executeStatement(
            'DELETE FROM notification WHERE id IN (
                SELECT id FROM notification WHERE read_at IS NOT NULL ORDER BY occurred_at ASC, id ASC LIMIT ?
            )',
            [$excess],
        );

        $excess = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM notification') - self::MAX_RECORDS;
        if ($excess > 0) {
            $this->connection->executeStatement(
                'DELETE FROM notification WHERE id IN (
                    SELECT id FROM notification ORDER BY occurred_at ASC, id ASC LIMIT ?
                )',
                [$excess],
            );
        }
    }
}
