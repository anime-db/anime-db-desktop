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

// color-mode.js is a self-invoking script that reads document.documentElement's
// data-theme-preference attribute and window.matchMedia() the instant it is required, so each
// test sets up the DOM/matchMedia state first and then requires it via loadColorMode(), which
// resets the module registry so the IIFE actually re-runs (a bare second require() would just
// return the already-executed, cached module).

function mockMatchMedia(matches) {
    const listeners = [];
    const mediaQueryList = {
        matches,
        addEventListener: jest.fn((event, callback) => listeners.push(callback)),
        removeEventListener: jest.fn(),
    };
    window.matchMedia = jest.fn().mockReturnValue(mediaQueryList);

    return {
        mediaQueryList,
        trigger(newMatches) {
            mediaQueryList.matches = newMatches;
            listeners.forEach((callback) => callback());
        },
    };
}

function loadColorMode() {
    jest.resetModules();
    require('../../app/assets/js/color-mode.js');
}

beforeEach(() => {
    document.documentElement.removeAttribute('data-theme-preference');
    document.documentElement.removeAttribute('data-bs-theme');
});

afterEach(() => {
    delete window.matchMedia;
});

test('pins data-bs-theme to light when the preference is light, without subscribing to the system theme', () => {
    document.documentElement.setAttribute('data-theme-preference', 'light');
    const { mediaQueryList } = mockMatchMedia(true);

    loadColorMode();

    expect(document.documentElement.getAttribute('data-bs-theme')).toBe('light');
    expect(mediaQueryList.addEventListener).not.toHaveBeenCalled();
});

test('pins data-bs-theme to dark when the preference is dark, without subscribing to the system theme', () => {
    document.documentElement.setAttribute('data-theme-preference', 'dark');
    const { mediaQueryList } = mockMatchMedia(false);

    loadColorMode();

    expect(document.documentElement.getAttribute('data-bs-theme')).toBe('dark');
    expect(mediaQueryList.addEventListener).not.toHaveBeenCalled();
});

test('follows prefers-color-scheme and stays subscribed when the preference is system', () => {
    document.documentElement.setAttribute('data-theme-preference', 'system');
    const { mediaQueryList, trigger } = mockMatchMedia(false);

    loadColorMode();

    expect(document.documentElement.getAttribute('data-bs-theme')).toBe('light');
    expect(mediaQueryList.addEventListener).toHaveBeenCalledWith('change', expect.any(Function));

    trigger(true);

    expect(document.documentElement.getAttribute('data-bs-theme')).toBe('dark');
});

test('follows prefers-color-scheme when the preference attribute is absent', () => {
    const { mediaQueryList } = mockMatchMedia(true);

    loadColorMode();

    expect(document.documentElement.getAttribute('data-bs-theme')).toBe('dark');
    expect(mediaQueryList.addEventListener).toHaveBeenCalled();
});

test('follows prefers-color-scheme when the preference value is not recognized', () => {
    document.documentElement.setAttribute('data-theme-preference', 'bogus');
    const { mediaQueryList } = mockMatchMedia(false);

    loadColorMode();

    expect(document.documentElement.getAttribute('data-bs-theme')).toBe('light');
    expect(mediaQueryList.addEventListener).toHaveBeenCalled();
});
