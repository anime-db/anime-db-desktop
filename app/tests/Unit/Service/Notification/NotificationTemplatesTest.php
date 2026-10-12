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

namespace App\Tests\Unit\Service\Notification;

use PHPUnit\Framework\TestCase;

/**
 * The text of a notification comes only from NotificationRenderer: a template that runs a
 * `notification.*` key through `trans` would show text the renderability gate never checked.
 */
final class NotificationTemplatesTest extends TestCase
{
    public function testNoTemplateTranslatesANotificationKey(): void
    {
        $this->assertSame([], self::offendingLines(\dirname(__DIR__, 4).'/templates'));
    }

    public function testTheScannerFindsATranslatedNotificationKey(): void
    {
        $dir = sys_get_temp_dir().'/notification_tpl_'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/a.html.twig', "<p>{{ 'app_notification.close_button'|trans }}</p>\n<p>{{ 'notification.x.title'|trans }}</p>\n<p>{{ trans('notification.x.body') }}</p>\n");

        try {
            $this->assertSame(['a.html.twig:2', 'a.html.twig:3'], self::offendingLines($dir));
        } finally {
            unlink($dir.'/a.html.twig');
            rmdir($dir);
        }
    }

    /** @return list<string> */
    private static function offendingLines(string $dir): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            foreach (file($file->getPathname()) ?: [] as $number => $line) {
                if (preg_match('/(?<![A-Za-z0-9_])notification\./', $line) === 1 && str_contains($line, 'trans')) {
                    $found[] = substr($file->getPathname(), \strlen($dir) + 1).':'.($number + 1);
                }
            }
        }
        sort($found);

        return $found;
    }
}
