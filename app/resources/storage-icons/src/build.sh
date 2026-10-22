#!/bin/sh
# AnimeDb package.
#
# @author    Peter Gribanov <info@peter-gribanov.ru>
# @copyright Copyright (c) 2026, Peter Gribanov
# @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program. If not, see <https://www.gnu.org/licenses/>.

# Rebuilds ../animedb-folder.ico and ../animedb-external.ico from the SVG sources in this
# directory. Requires rsvg-convert (librsvg2-bin) and icotool (icoutils). The icons it
# builds stay under LGPL-3.0-or-later (see ../README.md); this script is project code.
set -eu

cd "$(dirname "$0")"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

for name in animedb-folder animedb-external; do
    for size in 16 32 48 64 128 256; do
        rsvg-convert -w "$size" -h "$size" "$name.svg" -o "$tmp/$name-$size.png"
    done
    # 16-128 px stored as BMP, 256 px as embedded PNG (-r) — the usual Windows layout.
    icotool -c -o "../$name.ico" \
        "$tmp/$name-16.png" "$tmp/$name-32.png" "$tmp/$name-48.png" \
        "$tmp/$name-64.png" "$tmp/$name-128.png" -r "$tmp/$name-256.png"
done
