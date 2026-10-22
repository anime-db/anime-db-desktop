#!/bin/sh
# Rebuilds ../animedb-folder.ico and ../animedb-external.ico from the SVG sources in this
# directory. Requires rsvg-convert (librsvg2-bin) and icotool (icoutils).
#
# AnimeDB package.
# Copyright (c) 2026, Peter Gribanov. Licensed under LGPL-3.0-or-later like the icons it builds.
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
