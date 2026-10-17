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

; Plugin selection page (issue #1084). The installer neither downloads nor installs plugins: it
; only records the chosen plugin ids in "$APPDATA\AnimeDB\installer-plugins.json", and the app
; installs them on its first start through the same code path as the plugin market.
;
; The list of plugins comes from build/installer-plugins.nsh, generated at build time by
; "bin/console app:installer:plugins" from the signed plugin registry (see scripts/build.js). When
; that file declares no plugins, ANIMEDB_PLUGINS_PAGE is not defined and none of the code below is
; compiled in.
!include /NONFATAL "installer-plugins.nsh"

!ifdef ANIMEDB_PLUGINS_PAGE

!include LogicLib.nsh
!include nsDialogs.nsh

; English is declared first so that it is the fallback for every other installer language.
LangString AnimedbPluginsTitle 1033 "Plugins"
LangString AnimedbPluginsTitle 1049 "Плагины"
LangString AnimedbPluginsSubtitle 1033 "Choose plugins to install on first launch of AnimeDB. You can change this later in the app's plugin settings."
LangString AnimedbPluginsSubtitle 1049 "Выберите плагины, которые будут установлены при первом запуске AnimeDB. Изменить набор можно позже в разделе плагинов приложения."

Var AnimedbPluginsDialog
Var AnimedbPluginsJson
Var AnimedbPluginsPageShown

; The page is shown only on a first install: no data.db in userData (created by the app's first
; start and kept on uninstall, unlike ${isUpdated} or plugins.json, which do not mark a first
; install reliably), no earlier selection file, not a silent run, and only for the current user
; (in the "all users" mode $APPDATA points at ProgramData while the app reads the user profile).
Function animedbPluginsPageCreate
  ${If} ${Silent}
    Abort
  ${EndIf}
  ${If} $installMode != "CurrentUser"
    Abort
  ${EndIf}
  ${If} ${FileExists} "$APPDATA\AnimeDB\data.db"
    Abort
  ${EndIf}
  ${If} ${FileExists} "$APPDATA\AnimeDB\installer-plugins.json"
    Abort
  ${EndIf}

  !insertmacro MUI_HEADER_TEXT "$(AnimedbPluginsTitle)" "$(AnimedbPluginsSubtitle)"
  nsDialogs::Create 1018
  Pop $AnimedbPluginsDialog
  ${If} $AnimedbPluginsDialog == error
    Abort
  ${EndIf}

  !insertmacro animedbPluginsCreateCheckboxes
  StrCpy $AnimedbPluginsPageShown "1"
  nsDialogs::Show
FunctionEnd

Function animedbPluginsPageLeave
  !insertmacro animedbPluginsReadSelection
FunctionEnd

!macro customPageAfterChangeDir
  Page custom animedbPluginsPageCreate animedbPluginsPageLeave
!macroend

; Written here rather than on the page, so that a cancelled installation leaves no trace.
!macro customInstall
  ${If} $AnimedbPluginsPageShown == "1"
    CreateDirectory "$APPDATA\AnimeDB"
    FileOpen $0 "$APPDATA\AnimeDB\installer-plugins.json" w
    FileWrite $0 '{"plugins":[$AnimedbPluginsJson]}'
    FileClose $0
  ${EndIf}
!macroend

!endif
