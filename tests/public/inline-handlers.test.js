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

// Loads the real inline-handlers.js and drives it through document-level events, since the module
// is a self-invoking browser script with no exports. It registers its listeners on `document`
// itself, which — unlike DOM nodes rebuilt per test via body.innerHTML — is not torn down between
// tests in the same file, so the module is required exactly once (in beforeAll) rather than per
// test; requiring it again per test would stack duplicate listeners and multiply the observed
// calls. jsdom throws "Not implemented: HTMLFormElement.prototype.submit" for a real submit() call,
// so `data-submit-on-change` is verified through a spy on HTMLFormElement.prototype.submit rather
// than a real navigation. `data-confirm` is verified by dispatching a real, cancelable `submit`
// event and checking defaultPrevented, which is what a delegated listener calling
// event.preventDefault() actually affects.

beforeAll(() => {
    require('../../app/public/js/inline-handlers.js');
});

const originalFormSubmit = HTMLFormElement.prototype.submit;

beforeEach(() => {
    document.body.innerHTML = '';
    HTMLFormElement.prototype.submit = jest.fn();
});

afterEach(() => {
    delete window.confirm;
    HTMLFormElement.prototype.submit = originalFormSubmit;
});

describe('data-submit-on-change', () => {
    test('submits the owning form when the marked element changes', () => {
        document.body.innerHTML = `
            <form>
                <select id="locale" name="locale" data-submit-on-change>
                    <option value="en">en</option>
                    <option value="ru">ru</option>
                </select>
            </form>
        `;

        document.getElementById('locale').dispatchEvent(new Event('change', { bubbles: true }));

        expect(HTMLFormElement.prototype.submit).toHaveBeenCalledTimes(1);
    });

    test('does not submit a form for a change event unrelated to the marked element', () => {
        document.body.innerHTML = `
            <form>
                <select id="locale" name="locale"></select>
            </form>
        `;

        document.getElementById('locale').dispatchEvent(new Event('change', { bubbles: true }));

        expect(HTMLFormElement.prototype.submit).not.toHaveBeenCalled();
    });

    test('works for markup added to the DOM after the initial load, e.g. by HTMX', () => {
        document.body.innerHTML = '<div id="widgets"></div>';

        document.getElementById('widgets').innerHTML = `
            <form>
                <input type="checkbox" name="active" data-submit-on-change>
            </form>
        `;
        document.querySelector('[data-submit-on-change]').dispatchEvent(new Event('change', { bubbles: true }));

        expect(HTMLFormElement.prototype.submit).toHaveBeenCalledTimes(1);
    });
});

describe('data-confirm', () => {
    function setUpDeleteForm() {
        document.body.innerHTML = `
            <form data-confirm="Delete &quot;My Storage&quot;?">
                <button type="submit">Delete</button>
            </form>
        `;
    }

    function dispatchSubmit(form) {
        const submitEvent = new Event('submit', { bubbles: true, cancelable: true });
        form.dispatchEvent(submitEvent);

        return submitEvent;
    }

    test('lets the form submit through when the user confirms', () => {
        setUpDeleteForm();
        window.confirm = jest.fn(() => true);

        const submitEvent = dispatchSubmit(document.querySelector('form'));

        expect(window.confirm).toHaveBeenCalledWith('Delete "My Storage"?');
        expect(submitEvent.defaultPrevented).toBe(false);
    });

    test('cancels the submit when the user declines the confirmation', () => {
        setUpDeleteForm();
        window.confirm = jest.fn(() => false);

        const submitEvent = dispatchSubmit(document.querySelector('form'));

        expect(window.confirm).toHaveBeenCalledWith('Delete "My Storage"?');
        expect(submitEvent.defaultPrevented).toBe(true);
    });

    test('does not touch a submit from a form without data-confirm', () => {
        document.body.innerHTML = '<form><button type="submit">Scan</button></form>';
        window.confirm = jest.fn(() => false);

        const submitEvent = dispatchSubmit(document.querySelector('form'));

        expect(window.confirm).not.toHaveBeenCalled();
        expect(submitEvent.defaultPrevented).toBe(false);
    });

    test('works for a form added to the DOM after the initial load, e.g. by HTMX', () => {
        document.body.innerHTML = '<div id="storage-list"></div>';
        window.confirm = jest.fn(() => false);

        document.getElementById('storage-list').innerHTML = `
            <form data-confirm="Delete &quot;My Storage&quot;?">
                <button type="submit">Delete</button>
            </form>
        `;
        const submitEvent = dispatchSubmit(document.querySelector('form'));

        expect(window.confirm).toHaveBeenCalledWith('Delete "My Storage"?');
        expect(submitEvent.defaultPrevented).toBe(true);
    });
});

// jsdom never triggers a real network load, so `error` is dispatched by hand rather than by
// pointing `src` at a missing file. The event is not marked `bubbles: true` on purpose: a real
// image load failure does not bubble either, and the listener is only useful if it still catches
// it during the capture phase.
describe('cover image load error', () => {
    test('replaces an anime card thumbnail with the placeholder tile', () => {
        document.body.innerHTML = '<img class="anime-card__thumb" src="app-media://anime/1/cover.webp" alt="Title">';
        const image = document.querySelector('img');

        image.dispatchEvent(new Event('error'));

        expect(document.querySelector('img')).toBeNull();
        const placeholder = document.querySelector('.anime-card__thumb');
        expect(placeholder.tagName).toBe('DIV');
        expect(placeholder.classList.contains('anime-card__thumb--placeholder')).toBe(true);
    });

    test('replaces the anime detail cover with the placeholder tile', () => {
        document.body.innerHTML = '<img class="anime-detail__cover" src="app-media://anime/1/cover.webp" alt="Title">';
        const image = document.querySelector('img');

        image.dispatchEvent(new Event('error'));

        expect(document.querySelector('img')).toBeNull();
        const placeholder = document.querySelector('.anime-detail__cover');
        expect(placeholder.tagName).toBe('DIV');
        expect(placeholder.classList.contains('anime-detail__cover--placeholder')).toBe(true);
    });

    test('leaves an unrelated image alone', () => {
        document.body.innerHTML = '<img class="anime-detail__gallery-image" src="app-media://anime/1/shot.webp" alt="">';
        const image = document.querySelector('img');

        image.dispatchEvent(new Event('error'));

        expect(document.querySelector('img')).toBe(image);
    });
});
