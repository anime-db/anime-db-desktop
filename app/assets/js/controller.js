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

// Registry that mounts UI controls on htmx:load instead of each module walking the DOM once in
// its own <script> body (issue #734). A control is any root element carrying data-control="name"
// (several names, space-separated, mount independently); the mount function itself is supplied by
// registerControl(name, mountFn), normally called once at module-load time by the module that
// owns that control. htmx dispatches htmx:load both on the fragment it just settled into the
// document and once on <body> when the initial page finishes loading (see htmx.js, onLoad/
// triggerEvent), so this single listener covers first render and every later swap without a
// separate DOMContentLoaded path.
(function () {
    // Keyed by the mounted root element. The value maps a control name already mounted on that
    // element to the cleanup function mountFn returned (or null if it returned nothing) — doubles
    // as the "already mounted" marker a repeat htmx:load on the same element must not mount twice
    // (issue #734), e.g. a self-refreshing hx-trigger="load delay:2s" area that never actually
    // changes the element identity between reloads.
    const registry = new Map();
    const mounted = new WeakMap();

    function registerControl(name, mountFn) {
        registry.set(name, mountFn);
    }

    function controlNames(node) {
        const value = node.getAttribute('data-control');

        return value ? value.split(/\s+/).filter(Boolean) : [];
    }

    // Unknown control names have no devtools to surface in on a release build (devtools are
    // closed there) — a visible in-page notice next to the offending element is the one signal
    // guaranteed to reach whoever is looking at the page, on top of the console message devtools
    // would show a developer.
    function reportUnknownControl(name, node) {
        console.error(`[controller] Unknown control "${name}" requested on`, node);

        if (!window.AppTranslations) {
            return;
        }

        window.AppTranslations.trans('controller.unknown_control_text', { name }).then((text) => {
            const notice = document.createElement('p');
            notice.className = 'alert alert-danger';
            notice.setAttribute('role', 'alert');
            notice.textContent = text;
            node.prepend(notice);
        });
    }

    function mountNode(node) {
        const names = controlNames(node);
        if (names.length === 0) {
            return;
        }

        let mountedNames = mounted.get(node);
        if (!mountedNames) {
            mountedNames = new Map();
            mounted.set(node, mountedNames);
        }

        names.forEach((name) => {
            if (mountedNames.has(name)) {
                return;
            }

            const mountFn = registry.get(name);
            if (!mountFn) {
                mountedNames.set(name, null);
                reportUnknownControl(name, node);

                return;
            }

            // Recorded before mountFn runs, not after: a control that throws must still count as
            // "mounted" (issue #734's own gate against a second attempt on a repeat event) rather
            // than being retried forever, and a control that fails to mount cleanly should not be
            // treated as an easier case than one that fails to unmount cleanly.
            mountedNames.set(name, null);
            try {
                const unmount = mountFn(node);
                if (typeof unmount === 'function') {
                    mountedNames.set(name, unmount);
                }
            } catch (error) {
                // One control throwing must not stop the rest of the page from mounting (issue
                // #734) — every other data-control node, and every other name on this same node,
                // still gets its turn.
                console.error(`[controller] Failed to mount control "${name}"`, error);
            }
        });
    }

    function unmountNode(node) {
        const mountedNames = mounted.get(node);
        if (!mountedNames) {
            return;
        }
        mounted.delete(node);

        mountedNames.forEach((unmount, name) => {
            if (typeof unmount !== 'function') {
                return;
            }
            try {
                unmount();
            } catch (error) {
                console.error(`[controller] Failed to unmount control "${name}"`, error);
            }
        });
    }

    function forEachControlNode(root, callback) {
        if (!root || root.nodeType !== Node.ELEMENT_NODE) {
            return;
        }
        if (root.hasAttribute('data-control')) {
            callback(root);
        }
        root.querySelectorAll('[data-control]').forEach(callback);
    }

    // htmx:load, never htmx:afterSwap: afterSwap fires before htmx has finished processing the new
    // nodes (hx-* attributes not yet wired up), load fires once settling is done — see the PR
    // description for the full comparison against node_modules/htmx.org/dist/htmx.js.
    document.addEventListener('htmx:load', (event) => {
        forEachControlNode(event.target, mountNode);
    });

    // htmx fires this once per removed element, recursively down the whole subtree being cleaned
    // up (cleanUpElement() in htmx.js) — so a document-level listener sees every data-control node
    // being torn down individually, with no need to walk descendants here as well.
    document.addEventListener('htmx:beforeCleanupElement', (event) => {
        unmountNode(event.target);
    });

    window.Controller = { registerControl };
})();
