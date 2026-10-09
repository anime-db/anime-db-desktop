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

// Exercises the "settings-label-rename" control (issue #823) against a minimal DOM shaped like
// the row settings/label/index.html.twig renders. form.requestSubmit() is stubbed rather than
// left to run for real: jsdom does not implement real navigation, and a stub lets every test
// assert on whether a save was actually attempted instead of on page-navigation side effects.
//
// controller.js is required once at file scope — see controller.test.js for why a fresh
// require() per test would leak document-level listeners.
require('../../app/assets/js/controller.js');

function mountControls(root = document.body) {
    root.dispatchEvent(new CustomEvent('htmx:load', { bubbles: true, detail: { elt: root } }));
}

function setUpDom(name = 'favorite') {
    document.body.innerHTML = `
        <form data-control="settings-label-rename">
            <button type="button" data-settings-label-name-button hidden><bdi>${name}</bdi></button>
            <span data-settings-label-input-group>
                <input type="text" name="name" value="${name}" maxlength="32" required data-settings-label-name-input>
                <button type="submit">Save</button>
            </span>
            <p data-settings-label-name-error hidden>Tag name cannot be empty.</p>
        </form>
    `;
}

function loadSettingsLabelsModule() {
    jest.isolateModules(() => {
        require('../../app/assets/js/settings-labels.js');
    });
    mountControls();
}

function nameButton() {
    return document.querySelector('[data-settings-label-name-button]');
}

function inputGroup() {
    return document.querySelector('[data-settings-label-input-group]');
}

function input() {
    return document.querySelector('[data-settings-label-name-input]');
}

function errorBox() {
    return document.querySelector('[data-settings-label-name-error]');
}

function pressKey(key) {
    input().dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));
}

beforeEach(() => {
    jest.resetModules();
});

test('mounting shows a plain clickable name and hides the rename form', () => {
    setUpDom('favorite');
    loadSettingsLabelsModule();

    expect(nameButton().hidden).toBe(false);
    expect(inputGroup().hidden).toBe(true);
});

test('clicking the name opens the input, pre-filled with the current value', () => {
    setUpDom('favorite');
    loadSettingsLabelsModule();

    nameButton().click();

    expect(nameButton().hidden).toBe(true);
    expect(inputGroup().hidden).toBe(false);
    expect(input().value).toBe('favorite');
});

test('pressing Enter with a changed name submits the rename form', () => {
    setUpDom('favorite');
    loadSettingsLabelsModule();
    const requestSubmit = jest.spyOn(document.querySelector('form'), 'requestSubmit').mockImplementation(() => {});

    nameButton().click();
    input().value = 'rewatch';
    pressKey('Enter');

    expect(requestSubmit).toHaveBeenCalledTimes(1);
    expect(errorBox().hidden).toBe(true);
});

test('pressing Escape reverts the value and cancels without submitting', () => {
    setUpDom('favorite');
    loadSettingsLabelsModule();
    const requestSubmit = jest.spyOn(document.querySelector('form'), 'requestSubmit').mockImplementation(() => {});

    nameButton().click();
    input().value = 'rewatch';
    pressKey('Escape');

    expect(input().value).toBe('favorite');
    expect(requestSubmit).not.toHaveBeenCalled();
    expect(nameButton().hidden).toBe(false);
    expect(inputGroup().hidden).toBe(true);
});

test('an empty name shows the existing empty-name error and does not submit', () => {
    setUpDom('favorite');
    loadSettingsLabelsModule();
    const requestSubmit = jest.spyOn(document.querySelector('form'), 'requestSubmit').mockImplementation(() => {});

    nameButton().click();
    input().value = '   ';
    pressKey('Enter');

    expect(requestSubmit).not.toHaveBeenCalled();
    expect(errorBox().hidden).toBe(false);
    expect(inputGroup().hidden).toBe(false);
});

test('losing focus with an unchanged value reverts to read mode without submitting', () => {
    setUpDom('favorite');
    loadSettingsLabelsModule();
    const requestSubmit = jest.spyOn(document.querySelector('form'), 'requestSubmit').mockImplementation(() => {});

    nameButton().click();
    input().dispatchEvent(new Event('focusout', { bubbles: true }));

    expect(requestSubmit).not.toHaveBeenCalled();
    expect(nameButton().hidden).toBe(false);
    expect(inputGroup().hidden).toBe(true);
});

test('losing focus outside the form with a changed value submits the rename form', () => {
    setUpDom('favorite');
    loadSettingsLabelsModule();
    const requestSubmit = jest.spyOn(document.querySelector('form'), 'requestSubmit').mockImplementation(() => {});

    nameButton().click();
    input().value = 'rewatch';
    input().dispatchEvent(new FocusEvent('focusout', { bubbles: true, relatedTarget: document.body }));

    expect(requestSubmit).toHaveBeenCalledTimes(1);
});

test('losing focus to the Save button does not trigger a duplicate submit', () => {
    setUpDom('favorite');
    loadSettingsLabelsModule();
    const requestSubmit = jest.spyOn(document.querySelector('form'), 'requestSubmit').mockImplementation(() => {});

    nameButton().click();
    input().value = 'rewatch';
    const saveButton = document.querySelector('[data-settings-label-input-group] button[type="submit"]');
    input().dispatchEvent(new FocusEvent('focusout', { bubbles: true, relatedTarget: saveButton }));

    expect(requestSubmit).not.toHaveBeenCalled();
    expect(inputGroup().hidden).toBe(false);
});

test('Escape cancels the edit and moves focus to the label name button', () => {
    setUpDom();
    loadSettingsLabelsModule();
    nameButton().click();
    input().value = 'changed';
    expect(document.activeElement).toBe(input());

    pressKey('Escape');

    expect(input().value).toBe('favorite');
    expect(inputGroup().hidden).toBe(true);
    expect(document.activeElement).toBe(nameButton());
});
