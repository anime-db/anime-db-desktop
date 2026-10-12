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

namespace App\Tests\Support;

use App\Service\Notification\NotificationKindDefinition;
use App\Service\Notification\NotificationKindRegistry;
use App\Service\Notification\NotificationRenderer;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * The renderability gate over the golden fixtures `<kind>.v<N>.json`: every stored shape of a
 * record ever released must still render without degradation. Returns violations instead of
 * asserting, so the gate itself can be tested for its red cases.
 */
final class NotificationRenderabilityGate
{
    /**
     * @param list<string> $locales the built-in locales
     */
    public function __construct(
        private readonly NotificationKindRegistry $registry,
        private readonly NotificationRenderer $renderer,
        private readonly TranslatorBagInterface $translatorBag,
        private readonly string $fixturesDir,
        private readonly array $locales = ['ru', 'en'],
    ) {
    }

    /** @return list<string> */
    public function violations(): array
    {
        $fixtures = $this->loadFixtures();
        $violations = [];

        foreach (array_keys($fixtures) as $kind) {
            if ($this->registry->tryFrom($kind) === null) {
                $violations[] = \sprintf('fixture of "%s" refers to no kind definition', $kind);
            }
        }

        foreach ($this->registry->all() as $definition) {
            $versions = $fixtures[$definition->kind] ?? [];
            if ($versions === []) {
                $violations[] = \sprintf('kind "%s" has no fixture', $definition->kind);
                continue;
            }

            ksort($versions);
            $latest = end($versions);
            if (self::normalize($latest['row']) !== self::normalize($this->factoryRow($definition))) {
                $violations[] = \sprintf('latest fixture %s differs from what the factory builds', $latest['file']);
            }

            foreach ($versions as $fixture) {
                array_push($violations, ...$this->renderViolations($definition, $fixture));
            }
        }

        return $violations;
    }

    /**
     * @param array{file: string, row: array<string, mixed>} $fixture
     *
     * @return list<string>
     */
    private function renderViolations(NotificationKindDefinition $definition, array $fixture): array
    {
        $violations = [];
        /** @var array<string, mixed> $params */
        $params = \is_array($fixture['row']['params'] ?? null) ? $fixture['row']['params'] : [];

        $variants = [$params];
        foreach ($definition->params as $name => $param) {
            if ($param->codes === []) {
                continue;
            }
            $variants = array_merge(...array_map(
                static fn (array $variant): array => array_map(static fn (string $code): array => [$name => $code] + $variant, $param->codes),
                $variants,
            ));
        }

        foreach ($this->locales as $locale) {
            $catalogue = $this->translatorBag->getCatalogue($locale);
            foreach ($definition->translationKeys() as $key) {
                if (!$catalogue->defines($key, 'messages')) {
                    $violations[] = \sprintf('%s: key "%s" is not defined in the "%s" catalogue itself', $fixture['file'], $key, $locale);
                }
            }
            foreach ($variants as $variant) {
                if ($this->renderer->renderInLocale($definition, $variant, $locale)->degraded) {
                    $violations[] = \sprintf('%s renders degraded in "%s" with %s', $fixture['file'], $locale, json_encode($variant));
                }
            }
        }

        return $violations;
    }

    /** @return array<string, mixed> */
    private function factoryRow(NotificationKindDefinition $definition): array
    {
        $draft = ($definition->sample)();

        /** @var array<string, mixed> $row */
        $row = json_decode(json_encode([
            'kind' => $draft->kind,
            'severity' => $definition->severity->value,
            'params' => $draft->params,
            'subject' => $draft->subject(),
        ], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        return $row;
    }

    /** @return array<string, array<int, array{file: string, row: array<string, mixed>}>> */
    private function loadFixtures(): array
    {
        $fixtures = [];
        foreach (glob($this->fixturesDir.'/*.json') ?: [] as $path) {
            if (preg_match('/^([a-z][a-z0-9_]*)\.v(\d+)\.json$/', basename($path), $m) !== 1) {
                continue;
            }
            $fixtures[$m[1]][(int) $m[2]] = [
                'file' => basename($path),
                'row' => json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR),
            ];
        }

        return $fixtures;
    }

    private static function normalize(mixed $value): mixed
    {
        if (\is_array($value)) {
            ksort($value);

            return array_map(self::normalize(...), $value);
        }

        return $value;
    }
}
