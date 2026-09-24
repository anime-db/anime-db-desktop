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

const { PageErrorTracker } = require('../../scripts/shots/page-errors');

describe('PageErrorTracker.recordConsoleMessage', () => {
    test('a fresh tracker has no failures', () => {
        const tracker = new PageErrorTracker();
        expect(tracker.hasFailures()).toBe(false);
    });

    test('an "error" level console message is a failure', () => {
        const tracker = new PageErrorTracker();
        tracker.recordConsoleMessage('http://127.0.0.1/', { level: 'error', message: 'Uncaught TypeError: x is not a function' });

        expect(tracker.hasFailures()).toBe(true);
        expect(tracker.formatReport()).toBe('http://127.0.0.1/: console.error: Uncaught TypeError: x is not a function');
    });

    test.each(['warning', 'info', 'debug'])('a "%s" level console message is not a failure', (level) => {
        const tracker = new PageErrorTracker();
        tracker.recordConsoleMessage('http://127.0.0.1/', { level, message: 'noise' });

        expect(tracker.hasFailures()).toBe(false);
    });
});

describe('PageErrorTracker.recordFailedResource', () => {
    test('a non-2xx/3xx script response is a failure naming the resource URL', () => {
        const tracker = new PageErrorTracker();
        tracker.recordFailedResource('http://127.0.0.1/', {
            resourceType: 'script',
            url:          'http://127.0.0.1/js/main.js',
            statusCode:   404,
            error:        'net::OK',
        });

        expect(tracker.hasFailures()).toBe(true);
        expect(tracker.formatReport()).toBe('http://127.0.0.1/: failed to load script http://127.0.0.1/js/main.js: HTTP 404');
    });

    test('a network-level failure reports the webRequest error string', () => {
        const tracker = new PageErrorTracker();
        tracker.recordFailedResource('http://127.0.0.1/', {
            resourceType: 'script',
            url:          'http://127.0.0.1/js/main.js',
            error:        'net::ERR_CONNECTION_REFUSED',
        });

        expect(tracker.formatReport()).toBe(
            'http://127.0.0.1/: failed to load script http://127.0.0.1/js/main.js: net::ERR_CONNECTION_REFUSED',
        );
    });
});

describe('PageErrorTracker.recordFailure', () => {
    test('an arbitrary reason is recorded verbatim', () => {
        const tracker = new PageErrorTracker();
        tracker.recordFailure('http://127.0.0.1/', 'catalog grid did not settle within the timeout');

        expect(tracker.formatReport()).toBe('http://127.0.0.1/: catalog grid did not settle within the timeout');
    });
});

describe('PageErrorTracker.formatReport', () => {
    test('collects failures across multiple pages instead of stopping at the first one', () => {
        const tracker = new PageErrorTracker();
        tracker.recordConsoleMessage('http://127.0.0.1/', { level: 'error', message: 'first' });
        tracker.recordConsoleMessage('http://127.0.0.1/storage', { level: 'error', message: 'second' });

        expect(tracker.formatReport()).toBe(
            'http://127.0.0.1/: console.error: first\n' +
            'http://127.0.0.1/storage: console.error: second',
        );
    });
});
