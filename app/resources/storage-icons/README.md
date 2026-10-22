# Storage icons

`animedb-folder.ico` and `animedb-external.ico` are the folder / drive icons the
application places into storage roots (`desktop.ini` for a folder, `autorun.inf` for
a drive root).

They are derived from the [Oxygen Icon Theme](https://invent.kde.org/frameworks/oxygen-icons)
(KDE), tag `v6.31.0`:

- `scalable/places/hidef/server-database.svgz` → `src/server-database.svg`
- `scalable/devices/drive-harddisk.svgz` → `src/drive-harddisk.svg`

Copyright (C) 2007 Nuno Pinheiro, David Vignoni, David Miller, Johann Ollivier Lapeyre,
Kenneth Wimer, Riccardo Iaconelli and others.

**Modified by the AnimeDB project, 2026-10-22:**

- an unused embedded bitmap pattern (`sand_bitmap`) was removed from `server-database.svg`;
- `server-database` is recoloured to `#fc6703` with a gradient map (desaturate + per-channel
  tone table, see the `orange` filter in `src/animedb-*.svg`);
- `animedb-external` is a composition of the recoloured `server-database` over the unchanged
  `drive-harddisk`;
- both are rasterised to ICO (16–256 px) by `src/build.sh`.

Licensed under the GNU Lesser General Public License v3.0 or later — see
[`resources/third-party-licenses/texts/LGPL-3.0.txt`](../../../resources/third-party-licenses/texts/LGPL-3.0.txt)
(`THIRD-PARTY-LICENSES/texts/LGPL-3.0.txt` in the installation) and the GNU General Public
License v3.0 it supplements, [`LICENSE`](../../../LICENSE) (`LICENSE.txt` in the installation).
The corresponding source is the `src/` directory next to this file.
