; AnimeDb package.
;
; @author    Peter Gribanov <info@peter-gribanov.ru>
; @copyright Copyright (c) 2026, Peter Gribanov
; @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
;
; This program is free software: you can redistribute it and/or modify
; it under the terms of the GNU General Public License as published by
; the Free Software Foundation, either version 3 of the License, or
; (at your option) any later version.
;
; This program is distributed in the hope that it will be useful,
; but WITHOUT ANY WARRANTY; without even the implied warranty of
; MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
; GNU General Public License for more details.
;
; You should have received a copy of the GNU General Public License
; along with this program. If not, see <https://www.gnu.org/licenses/>.

; During installation, electron-builder's NSIS installer copies the full installer executable
; into "$LOCALAPPDATA\<updaterCacheDirName>\installer.exe" for electron-updater to use as a
; source for future update checks and differential updates. That copy is intentionally left in
; place after installation, so it is not removed here.
;
; The uninstaller, however, never touches $LOCALAPPDATA, so this cached copy survives an
; uninstall indefinitely and grows with every release. This macro removes that cache directory
; as part of uninstallation, without affecting the copy made during installation.
;
; The directory name below must stay in sync with "build.nsis.updaterCacheDirName" in
; package.json (electron-builder defaults it to "<package.name>-updater" when unset).
!macro customUnInstall
  RMDir /r "$LOCALAPPDATA\anime-db-desktop-updater"
!macroend
