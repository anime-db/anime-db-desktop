/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 *
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

'use strict';

const fs = require('fs');
const path = require('path');

// electron-builder includes the user script (nsis.include) in the common header, which is placed
// BEFORE installer.nsi. Variables declared by its templates (e.g. "Var installMode" in
// multiUser.nsh) do not exist yet when build/installer.nsh is parsed; makensis -WX turns the
// resulting warning 6000 into a failed build that only shows up in build.yml.
const TEMPLATE_VARIABLES = ['installMode', 'installAppData', 'appExe', 'installerMode'];

const readCode = () =>
  fs
    .readFileSync(path.join(__dirname, '..', '..', 'build', 'installer.nsh'), 'utf8')
    .split(/\r?\n/)
    .filter((line) => !/^\s*[;#]/.test(line))
    .join('\n');

describe('build/installer.nsh', () => {
  test('includes MUI2.nsh when it uses MUI macros', () => {
    const code = readCode();

    if (/!insertmacro\s+MUI_/i.test(code)) {
      expect(code).toMatch(/^\s*!include\s+"?MUI2\.nsh"?\s*$/im);
    }
  });

  test('does not use variables declared by electron-builder templates', () => {
    const code = readCode();

    for (const name of TEMPLATE_VARIABLES) {
      expect(code).not.toMatch(new RegExp(`\\$\\{?${name}\\b`, 'i'));
    }
  });

  describe('Explorer context menu entry (issue #1122)', () => {
    const CLASSES = ['Directory', 'Directory\\Background', 'Drive'];
    const COMMAND = `'"$INSTDIR\\AnimeDB.exe" --explorer-open="%V"'`;
    const macroBody = (code, name) => {
      const m = code.match(new RegExp(`!macro ${name}\\b([\\s\\S]*?)!macroend`));
      return m ? m[1] : null;
    };

    test('customInstall and customUnInstall are defined exactly once', () => {
      const code = readCode();

      expect(code.match(/^\s*!macro customInstall\b/gim)).toHaveLength(1);
      expect(code.match(/^\s*!macro customUnInstall\b/gim)).toHaveLength(1);
    });

    test('customInstall is outside the plugins page condition, plugin writing stays inside', () => {
      const code = readCode();
      const before = code.slice(0, code.indexOf('!macro customInstall'));
      const opened = (before.match(/^\s*!ifdef\s+ANIMEDB_PLUGINS_PAGE\b/gim) || []).length;
      const closedByElse = (before.match(/^\s*!else\b/gim) || []).length;
      const closed = (before.match(/^\s*!endif\b/gim) || []).length;

      // the "!ifdef ANIMEDB_PLUGINS_PAGE" block is already closed at this point
      expect(opened).toBe(1);
      expect(closedByElse + closed).toBeGreaterThanOrEqual(opened + 1); // + the !ifndef BUILD_UNINSTALLER is still open
      const pluginsBlock = code.slice(code.indexOf('!ifdef ANIMEDB_PLUGINS_PAGE'), code.indexOf('!else'));
      expect(pluginsBlock).toContain('installer-plugins.json');
      expect(macroBody(code, 'customInstall')).not.toContain('installer-plugins.json');
    });

    test('customInstall writes MUIVerb, Icon and command for all three classes', () => {
      const body = macroBody(readCode(), 'customInstall');

      for (const cls of CLASSES) {
        const key = `"Software\\Classes\\${cls}\\shell\\AnimeDB`;
        expect(body).toContain(`WriteRegStr SHCTX ${key}" "MUIVerb" "$(AnimedbExplorerVerb)"`);
        expect(body).toContain(`WriteRegStr SHCTX ${key}" "Icon" "$INSTDIR\\resources\\app\\resources\\tray-idle.ico"`);
        expect(body).toContain(`WriteRegStr SHCTX ${key}\\command" "" ${COMMAND}`);
      }
    });

    test('customInstall clears HKCU unconditionally before writing SHCTX', () => {
      const body = macroBody(readCode(), 'customInstall');
      const firstWrite = body.indexOf('WriteRegStr SHCTX');

      for (const cls of CLASSES) {
        const del = body.indexOf(`DeleteRegKey HKCU "Software\\Classes\\${cls}\\shell\\AnimeDB"`);
        expect(del).toBeGreaterThanOrEqual(0);
        expect(del).toBeLessThan(firstWrite);
      }
      expect(body.slice(0, firstWrite)).not.toMatch(/\$\{(If|IfNot|Unless)\}/i);
    });

    test('the Icon path is documented to depend on asar:false, which the build config sets', () => {
      const raw = fs.readFileSync(path.join(__dirname, '..', '..', 'build', 'installer.nsh'), 'utf8');
      const pkg = JSON.parse(fs.readFileSync(path.join(__dirname, '..', '..', 'package.json'), 'utf8'));

      expect(raw).toMatch(/;[^\n]*asar"?: false/);
      expect(pkg.build.asar).toBe(false);
      expect(fs.existsSync(path.join(__dirname, '..', '..', 'resources', 'tray-idle.ico'))).toBe(true);
    });

    test('MUIVerb LangString exists for en and ru outside the plugins page condition', () => {
      const code = readCode();

      expect(code).toMatch(/^\s*LangString AnimedbExplorerVerb 1033 "/m);
      expect(code).toMatch(/^\s*LangString AnimedbExplorerVerb 1049 "/m);
      expect(code.indexOf('LangString AnimedbExplorerVerb')).toBeGreaterThan(code.indexOf('!else'));
    });

    test('customUnInstall removes the keys under ifNot isUpdated', () => {
      const body = macroBody(readCode(), 'customUnInstall');
      const guard = body.indexOf('${ifNot} ${isUpdated}');

      expect(guard).toBeGreaterThanOrEqual(0);
      for (const cls of CLASSES) {
        const del = body.indexOf(`DeleteRegKey SHCTX "Software\\Classes\\${cls}\\shell\\AnimeDB"`);
        expect(del).toBeGreaterThan(guard);
        expect(del).toBeLessThan(body.indexOf('${endif}'));
      }
    });
  });
});
