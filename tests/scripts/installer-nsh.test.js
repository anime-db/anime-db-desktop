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

describe('build/installer.nsh', () => {
  test('does not use variables declared by electron-builder templates', () => {
    const code = fs
      .readFileSync(path.join(__dirname, '..', '..', 'build', 'installer.nsh'), 'utf8')
      .split(/\r?\n/)
      .filter((line) => !/^\s*[;#]/.test(line))
      .join('\n');

    for (const name of TEMPLATE_VARIABLES) {
      expect(code).not.toMatch(new RegExp(`\\$\\{?${name}\\b`, 'i'));
    }
  });
});
