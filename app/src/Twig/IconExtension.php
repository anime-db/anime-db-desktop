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

namespace App\Twig;

use App\Twig\Exception\UnknownIconException;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `icon('trash')` — the markup of a single SVG from templates/icons/, always decorative
 * (`aria-hidden="true"` is baked into every file there). Unlike the earlier
 * `include('_icon.html.twig', {name: ...})` form, the icon name is validated against the actual
 * files on disk at call time: an unknown name throws instead of letting Twig's `source()` fail
 * deep inside a partial, and it throws for every call site regardless of how the name literal or
 * variable is written, which a text-scanning test over the .twig sources could never guarantee
 * (issue #829 review).
 *
 * The rendered markup also carries a `data-icon="<name>"` attribute on the root `<svg>`, naming
 * the icon that was actually requested — distinct from the file's own content, which a test
 * cannot otherwise tell apart from any other icon's (every file in templates/icons/ carries the
 * same generic `aria-hidden="true"` wrapper).
 */
final class IconExtension extends AbstractExtension
{
    public function __construct(private readonly string $iconsDir)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('icon', $this->render(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * @throws UnknownIconException when $name is not a file in templates/icons/
     */
    public function render(string $name): string
    {
        if (!preg_match('/^[a-z0-9-]+$/', $name) || !is_file($this->iconsDir.'/'.$name.'.svg')) {
            throw new UnknownIconException(\sprintf('Unknown icon "%s": no such file in %s.', $name, $this->iconsDir));
        }

        $svg = (string) file_get_contents($this->iconsDir.'/'.$name.'.svg');

        return (string) preg_replace('/^<svg /', \sprintf('<svg data-icon="%s" ', $name), $svg, 1);
    }
}
