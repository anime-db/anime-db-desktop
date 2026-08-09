# AnimeDb package.
#
# @author    Peter Gribanov <info@peter-gribanov.ru>
# @copyright Copyright (c) 2026, Peter Gribanov
# @license   https://gnu.org GPL-3.0-or-later
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
# along with this program. If not, see <https://gnu.org>.

# Picked up automatically by electron-builder's NSIS target (default resource name
# "installer.nsh" in the default buildResources dir "build/") — no "nsis.include" wiring needed
# in package.json.
#
# qbittorrent-nox.exe listens for inbound BT peer connections on a pinned port (see
# native/supervisor/qbittorrent.js, BT_PORT = 51413, issue #345) and Windows would otherwise
# show its own inbound-firewall prompt the first time it does so — separate from the SmartScreen
# prompt for the installer/app .exe. A user who dismisses or denies that prompt ends up with
# BT listen blocked while outbound stays open, degrading swarm connectivity without any obvious
# error (issue #349). Adding a program-based allow rule here means the prompt never appears.
!macro customInstall
  # electron-builder currently packs "bin/**" into resources\app.asar without an asarUnpack
  # entry, so the physical qbittorrent-nox.exe on disk after unpacking can land either directly
  # under resources\app (asar disabled/unpacked) or under resources\app.asar.unpacked (asar
  # enabled with unpack) — pick whichever actually exists.
  StrCpy $R0 "$INSTDIR\resources\app.asar.unpacked\bin\qbittorrent-nox\qbittorrent-nox.exe"
  IfFileExists "$R0" +2 0
    StrCpy $R0 "$INSTDIR\resources\app\bin\qbittorrent-nox\qbittorrent-nox.exe"

  # A program-based rule survives the BT listen port ever changing; a fixed-port rule would not.
  # This installer runs per-user (no UAC) by default, but a firewall rule is machine-wide and
  # needs admin rights, so only this one command is elevated via ShellExecute's "runas" verb
  # rather than the whole installer. If the user declines the UAC prompt, netsh silently no-ops
  # and install continues — the user is back to the one manual Windows prompt this rule exists
  # to avoid, not a broken installation.
  DetailPrint "Adding Windows Firewall rule for qbittorrent-nox.exe"
  ExecShellWait "runas" "netsh" 'advfirewall firewall add rule name="AnimeDB qBittorrent (BT listen)" dir=in action=allow program="$R0" enable=yes profile=any' SW_HIDE
!macroend

!macro customUnInstall
  DetailPrint "Removing Windows Firewall rule for qbittorrent-nox.exe"
  ExecShellWait "runas" "netsh" 'advfirewall firewall delete rule name="AnimeDB qBittorrent (BT listen)"' SW_HIDE
!macroend
