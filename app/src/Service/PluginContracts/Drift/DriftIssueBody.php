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

namespace App\Service\PluginContracts\Drift;

use App\Service\PluginContracts\LaggingPlugin;
use App\Service\PluginContracts\LagReason;

final class DriftIssueBody
{
    public const string TITLE_FORMAT = 'Plugin contracts pin is behind the app (%s)';

    /**
     * @param list<string> $owners `@handle` mentions, empty when CODEOWNERS has no entry
     */
    public static function render(LaggingPlugin $plugin, string $contractsVersion, string $appVersion, array $owners): string
    {
        $lines = [
            \sprintf('Плагин `%s` отстаёт от версии контрактов приложения.', $plugin->id),
            '',
            \sprintf('- Последняя опубликованная версия плагина: %s.', $plugin->latestVersion ?? 'нет данных'),
            \sprintf('- Её пин `plugin-contracts`: %s.', $plugin->latestPin !== null ? \sprintf('`%s`', $plugin->latestPin) : 'не задан'),
            \sprintf('- Версия `plugin-contracts`, которую везёт релиз приложения: `%s`.', $contractsVersion),
            \sprintf('- Версия релиза приложения: %s.', $appVersion),
            \sprintf('- Причина: %s.', self::reasonText($plugin->reason)),
            '',
            'Что сделать:',
            '',
        ];

        foreach (self::steps($plugin->reason) as $step) {
            $lines[] = '- '.$step;
        }

        $lines[] = '';
        $lines[] = 'Issue закроется автоматически, когда очередной прогон сверки перестанет находить отставание.';

        if ($owners !== []) {
            $lines[] = '';
            $lines[] = \sprintf('cc %s', implode(' ', $owners));
        }

        $lines[] = '';
        $lines[] = (new DriftMarker($contractsVersion, $plugin->reason))->render();

        return implode("\n", $lines);
    }

    /**
     * @param DriftMarker|null $previous null when the previous state is unknown (the marker is unreadable)
     */
    public static function renderComment(?DriftMarker $previous, DriftMarker $current): string
    {
        if ($previous === null) {
            return 'Машиночитаемая пометка в теле issue не читалась (вероятно, тело правили вручную) и восстановлена.';
        }

        $lines = ['Состояние отставания изменилось с прошлого прогона.', ''];

        if ($previous->contractsVersion !== $current->contractsVersion) {
            $lines[] = \sprintf('- Версия `plugin-contracts` приложения: `%s` → `%s`.', $previous->contractsVersion, $current->contractsVersion);
        }

        if ($previous->reason !== $current->reason) {
            $lines[] = \sprintf('- Причина: %s → %s.', self::reasonText($previous->reason), self::reasonText($current->reason));
        }

        return implode("\n", $lines);
    }

    private static function reasonText(LagReason $reason): string
    {
        return match ($reason) {
            LagReason::NO_ACCEPTING_VERSION => 'ни одна опубликованная версия плагина не принимает эту версию контрактов',
            LagReason::NOT_PARSEABLE => 'манифест плагина не разбирается этой версией приложения',
        };
    }

    /**
     * @return list<string>
     */
    private static function steps(LagReason $reason): array
    {
        return match ($reason) {
            LagReason::NO_ACCEPTING_VERSION => [
                'поднять `require.plugin-contracts` в `plugins/<id>/manifest.json`, чтобы он принимал версию контрактов приложения;',
                'поднять версию плагина в том же PR — иначе `release.yml` пропустит релиз;',
                'прогнать тесты плагина локально на контрактах этой версии и указать результат в PR (лок-файлы в репозитории плагинов не отслеживаются);',
                'менять только `plugins/<id>/`;',
                'корневой `composer.json` не трогать — он намеренно широкий.',
            ],
            LagReason::NOT_PARSEABLE => [
                'выяснить, чей манифест впереди: плагина или приложения;',
                'поднимать пин `plugin-contracts` бесполезно — дело не в нём, манифест не разбирается целиком.',
            ],
        };
    }
}
