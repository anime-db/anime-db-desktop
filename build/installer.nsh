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
; The directory name below must stay in sync with the "name" field in package.json:
; electron-builder derives the cache directory name as "<package.name>-updater".
;
; The uninstaller also runs as part of installing a new version over an existing one, in
; which case the update cache must not be touched: it may be the very location the running
; installer was launched from, and removing it would break the update in progress. Guard the
; removal so it only runs on an actual uninstall, the same way electron-builder guards its
; own app-data removal.
!macro customUnInstall
  ${ifNot} ${isUpdated}
    RMDir /r "$LOCALAPPDATA\anime-db-desktop-updater"
  ${endif}
!macroend
