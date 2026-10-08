# Third-party licenses

AnimeDB is distributed as a Windows installer that bundles several
Free/Open-Source components. Each of them stays under its own license.

**Scope of licenses.** The AnimeDB code itself — everything under
`resources/app/native/`, `resources/app/app/src/`, `resources/app/app/templates/`
(except `resources/app/app/templates/icons/`, see Bootstrap Icons below)
and `resources/app/scripts/` — is distributed under the GNU General Public
License version 3 or later; its full text is in `LICENSE.txt` next to this
directory. The bundled third-party components listed below are **not** covered
by that license: each remains under the license named in the table, and nothing
in this product relicenses them.

**Required acknowledgment (PHP License 3.01, clause 6):**

> This product includes PHP software, freely available from
> <http://www.php.net/software/>.

All paths below are relative to the AnimeDB installation directory.

## Components

| Component                                                                                    | Version             | License                                              | Linkage in the product                                                            | Notice / full text                                                |
|----------------------------------------------------------------------------------------------|---------------------|------------------------------------------------------|-----------------------------------------------------------------------------------|-------------------------------------------------------------------|
| Electron / Chromium / Node.js                                                                | 35.7.5              | MIT, BSD-3-Clause a.o.                               | application runtime                                                               | `LICENSE.electron.txt`, `LICENSES.chromium.html`                  |
| PHP                                                                                          | 8.5.8               | PHP License 3.01                                     | separate process, dynamic DLLs                                                    | `PHP-NOTICE.txt`                                                  |
| FrankenPHP (incl. Caddy)                                                                     | 1.12.4              | MIT + Apache-2.0 + AGPL-3.0                          | separate process, static Go binary                                                | `FrankenPHP-NOTICE.txt`                                           |
| OpenSSL                                                                                      | 3.5.7               | Apache-2.0                                           | dynamic DLL, used by PHP                                                          | `OpenSSL-NOTICE.txt`, `texts/Apache-2.0.txt`                      |
| ICU                                                                                          | 77.1                | Unicode License v3                                   | dynamic DLL, used by PHP intl                                                     | `ICU-NOTICE.txt`, `texts/unicode-license-v3.txt`                  |
| Brotli                                                                                       | bundled with PHP    | MIT                                                  | dynamic DLL                                                                       | `Runtime-libraries-NOTICE.txt`, `texts/brotli-MIT.txt`            |
| pthreads4w                                                                                   | 3.0.0               | Apache-2.0                                           | dynamic DLL                                                                       | `Runtime-libraries-NOTICE.txt`, `texts/pthreads4w-Apache-2.0.txt` |
| watcher                                                                                      | bundled with PHP    | MIT                                                  | dynamic DLL                                                                       | `Runtime-libraries-NOTICE.txt`, `texts/watcher-MIT.txt`           |
| SQLite                                                                                       | bundled with PHP    | public domain                                        | dynamic DLL                                                                       | `SQLite-NOTICE.txt`                                               |
| libpng, libjpeg-turbo, libwebp, FreeType, zlib                                               | bundled with PHP    | libpng / IJG+BSD / BSD-3 / FTL / zlib                | static, inside `php_gd.dll`                                                       | `GD-CODECS-NOTICE.txt`                                            |
| PCRE2, libmagic, libmbfl, libgd, libzip, xxHash, Lexbor, uriparser a.o.                      | bundled with PHP    | BSD, MIT a.o.                                        | static, inside the PHP binaries                                                   | `resources/app/bin/licenses/frankenphp/readme-redist-bins.txt`    |
| Meilisearch                                                                                  | 1.13.0              | MIT                                                  | separate process, static Rust binary                                              | `Meilisearch-NOTICE.txt`, `texts/meilisearch-MIT.txt`             |
| qBittorrent-nox, Qt 6, libtorrent-rasterbar, boost, OpenSSL 3.6.3, zlib, PCRE2, MSVC runtime | see the bundle      | GPL-2.0-or-later, LGPL-3.0, BSD, BSL-1.0, Apache-2.0 | separate process                                                                  | `resources/app/bin/qbittorrent-nox/THIRD-PARTY-LICENSES/`         |
| ffprobe (FFmpeg), mingw-w64, GCC runtime, zlib, winpthreads                                  | 9.0.2               | LGPL-2.1-or-later a.o.                               | separate process, static binary                                                   | `resources/app/bin/ffprobe/THIRD-PARTY-LICENSES/`                 |
| htmx                                                                                         | 2.0.10              | 0BSD                                                 | JavaScript, loaded by the web UI                                                  | 0BSD requires no attribution; listed for completeness             |
| Bootstrap                                                                                    | 5.3.8               | MIT                                                  | CSS and JS, loaded by the web UI                                                  | `texts/bootstrap-MIT.txt`                                         |
| Bootstrap Icons                                                                              | 1.13.1              | MIT                                                  | SVG files copied into resources/app/app/templates/icons/, inlined into the web UI | `texts/bootstrap-icons-MIT.txt`                                   |
| PHP libraries under `app/vendor/`                                                            | per package         | MIT, BSD-3-Clause, GPL-3.0-or-later                  | interpreted PHP sources                                                           | `COMPOSER-PACKAGES.md`, one license file per package directory    |

Versions marked as read from the binaries were taken from the PE version
resources and embedded strings of the files actually shipped, not from
changelogs.

## Corresponding source

The bundled components under copyleft licenses are shipped **unmodified**, and
their corresponding source is the upstream release at the pinned tag. The exact
versions this product downloads and verifies by SHA-256 are recorded in
`resources/app/scripts/versions.json`.

- **PHP 8.5.8 and the FrankenPHP runtime** —
  <https://github.com/php/frankenphp/tree/v1.12.4>; the AGPL-3.0 and Apache-2.0
  components inside the binary are listed with their own upstream tags in
  `FrankenPHP-NOTICE.txt`.
- **Meilisearch 1.13.0** —
  <https://github.com/meilisearch/meilisearch/tree/v1.13.0>.
- **qBittorrent-nox 5.2.3 and Qt 6.10.3** —
  <https://github.com/gpslab/qbittorrent-nox-win-build>, tag `qbt-nox-5.2.3_2`,
  which also carries the build recipe; see
  `resources/app/bin/qbittorrent-nox/THIRD-PARTY-LICENSES/README.md`.
- **ffprobe (FFmpeg) 9.0.2** —
  <https://github.com/gpslab/ffprobe-win-build>, tag `ffprobe-9.0.2_1`, which
  also carries the build recipe. The FFmpeg source tarball,
  `ffmpeg-9.0.2.tar.xz`, is attached as a release asset to that same tag, so
  the corresponding source sits alongside the binary rather than on a
  separate server; see
  `resources/app/bin/ffprobe/THIRD-PARTY-LICENSES/README.md`.

These sources remain available at the addresses above for as long as the
corresponding binary builds are distributed.

## Relinking Qt 6 (LGPL-3.0)

Qt 6 is bundled as dynamic, replaceable DLLs, so you may substitute your own Qt
build: replace `Qt6*.dll` and the contents of `plugins/` in
`resources/app/bin/qbittorrent-nox/`. Corresponding source for Qt 6.10.3 is at
<https://download.qt.io/archive/qt/6.10/6.10.3/>. See
`resources/app/bin/qbittorrent-nox/THIRD-PARTY-LICENSES/Qt6-LGPL-NOTICE.txt`.

## Rebuilding ffprobe (LGPL-2.1-or-later)

FFmpeg is linked into `ffprobe.exe` **statically**, not as replaceable DLLs, so
the right to relink is exercised differently than for Qt 6 above: rebuild
`ffprobe.exe` from the corresponding source attached to the release (see
"Corresponding source" above) following the build recipe published in
<https://github.com/gpslab/ffprobe-win-build>, then replace
`resources/app/bin/ffprobe/ffprobe.exe` with the rebuilt binary. There are no
DLLs to swap. The application invokes `ffprobe.exe` as a separate process and
is not linked against any FFmpeg library itself.

## Microsoft Visual C++ runtime

The MSVC runtime DLLs are redistributed under the Microsoft Visual Studio
redistribution terms and are treated as System Libraries under GPL-3.0 section
1. See
`resources/app/bin/qbittorrent-nox/THIRD-PARTY-LICENSES/MSVC-runtime-NOTICE.txt`.
