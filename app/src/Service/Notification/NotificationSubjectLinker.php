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

use App\Entity\ValueObject\Exception\InvalidPluginIdException;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\SettingsPageRegistry;
use Doctrine\DBAL\Connection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the link of a notification subject (an anime, an installed plugin's settings page or the market page of a plugin). No subject, an unknown subject type or a subject that
 * no longer exists give no link: the record still shows its text from the `params` snapshot.
 */
class NotificationSubjectLinker
{
    /** @var array<string, array{route: string, table: string}> */
    private const SUBJECTS = [
        'anime' => ['route' => 'anime_show', 'table' => 'anime'],
    ];

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Connection $connection,
        private readonly SettingsPageRegistry $settingsPages,
    ) {
    }

    public function urlFor(?string $type, ?string $id): ?string
    {
        if ($type === 'plugin' && $id !== null) {
            return $this->pluginUrl($id);
        }

        if ($type === 'market_plugin' && $id !== null) {
            return $this->marketUrl($id);
        }

        if ($type === null || $id === null || !isset(self::SUBJECTS[$type]) || preg_match('/^\d+$/', $id) !== 1) {
            return null;
        }

        $subject = self::SUBJECTS[$type];
        $exists = $this->connection->fetchOne(\sprintf('SELECT 1 FROM %s WHERE id = ?', $subject['table']), [(int) $id]);
        if ($exists === false) {
            return null;
        }

        return $this->urlGenerator->generate($subject['route'], ['id' => (int) $id]);
    }

    /**
     * The market page has no per-plugin anchors, so the link is the page itself: the plugin's "Update" button is there.
     */
    private function marketUrl(string $id): ?string
    {
        try {
            new PluginId($id);
        } catch (InvalidPluginIdException) {
            return null;
        }

        return $this->urlGenerator->generate('settings_market_index');
    }

    private function pluginUrl(string $id): ?string
    {
        try {
            $pluginId = new PluginId($id);
        } catch (InvalidPluginIdException) {
            return null;
        }

        // Same reachability gate as the settings route: an installed but disabled plugin, or one without a settings page, is a 404 there.
        return $this->settingsPages->find($pluginId) !== null
            ? $this->urlGenerator->generate('settings_plugin_page', ['pluginId' => $id])
            : null;
    }
}
