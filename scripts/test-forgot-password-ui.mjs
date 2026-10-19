import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

let recoveryTimeout = null;
let pageshowHandler = null;
let fetchCalls = 0;
let assignedLocation = '';
let resolveFetch = null;

class MockButton {
    constructor() {
        this.dataset = { loadingLabel: 'Sending...' };
        this.disabled = false;
        this.textContent = 'Send Verification Code';
        this.attributes = new Map();
    }

    setAttribute(name, value) { this.attributes.set(name, value); }
    removeAttribute(name) { this.attributes.delete(name); }
    matches() { return false; }
}

class MockForm {
    constructor(button) {
        this.button = button;
        this.action = '/public/forgot-password.php';
        this.dataset = {};
        this.attributes = new Map();
        this.listeners = new Map();
        this.nativeSubmits = 0;
    }

    addEventListener(name, callback) { this.listeners.set(name, callback); }
    checkValidity() { return true; }
    querySelector() { return this.button; }
    setAttribute(name, value) { this.attributes.set(name, value); }
    removeAttribute(name) { this.attributes.delete(name); }
    submit() { this.nativeSubmits += 1; }
}

class MockFormData {
    constructor(form) { this.form = form; }
}

const button = new MockButton();
const form = new MockForm(button);
const feedback = { textContent: '', hidden: true };

globalThis.HTMLButtonElement = MockButton;
globalThis.document = {
    visibilityState: 'visible',
    querySelectorAll: () => [form],
    querySelector: (selector) => selector === '[data-password-recovery-feedback]' ? feedback : null,
    contains: (element) => element === form,
};
globalThis.window = {
    FormData: MockFormData,
    fetch: (_url, options) => {
        fetchCalls += 1;
        return new Promise((resolve, reject) => {
            resolveFetch = resolve;
            options.signal?.addEventListener('abort', () => reject({ name: 'AbortError' }), { once: true });
        });
    },
    location: {
        href: '/public/forgot-password.php',
        assign: (location) => { assignedLocation = location; },
    },
    addEventListener: (name, callback) => {
        if (name === 'pageshow') pageshowHandler = callback;
    },
};
globalThis.setTimeout = (callback) => {
    recoveryTimeout = callback;
    return 1;
};
globalThis.clearTimeout = () => {};

const source = readFileSync(new URL('../assets/js/forgot-password.js', import.meta.url), 'utf8');
eval(source);

const submit = (submitter = button) => {
    let prevented = false;
    const promise = form.listeners.get('submit')({
        defaultPrevented: false,
        submitter,
        preventDefault: () => { prevented = true; },
    });
    return { prevented, promise };
};

const first = submit();
assert.equal(first.prevented, true, 'Fetch-based recovery must prevent the native duplicate POST.');
assert.equal(button.disabled, true, 'The button was not disabled while sending.');
assert.equal(button.textContent, 'Sending...', 'The loading label was not shown.');
assert.equal(button.attributes.get('aria-busy'), 'true', 'The loading state is not accessible.');

const duplicate = submit();
await duplicate.promise;
assert.equal(fetchCalls, 1, 'A double-click submitted more than one recovery request.');

resolveFetch({
    ok: true,
    json: async () => ({ success: true, message: 'Code sent.', redirect: '/public/forgot-password.php' }),
});
await first.promise;
assert.equal(assignedLocation, '/public/forgot-password.php', 'A successful response did not continue to the code step.');
assert.equal(button.disabled, false, 'The success path left the button disabled.');
assert.equal(button.textContent, 'Send Verification Code', 'The success path kept the loading label.');

assignedLocation = '';
window.fetch = async () => ({
    ok: false,
    json: async () => ({ success: false, message: 'Unable to send.', redirect: '/public/forgot-password.php' }),
});
const failure = submit();
await failure.promise;
assert.equal(assignedLocation, '', 'A failed response incorrectly redirected away from its error message.');
assert.equal(button.disabled, false, 'The failure path left the button disabled.');
assert.equal(button.textContent, 'Send Verification Code', 'The failure path kept the loading label.');
assert.equal(feedback.textContent, 'Unable to send.', 'The backend failure message was not displayed.');

window.fetch = async () => ({
    ok: true,
    json: async () => { throw new SyntaxError('Invalid JSON'); },
});
const invalidResponse = submit();
await invalidResponse.promise;
assert.equal(button.disabled, false, 'An invalid backend response left the button disabled.');
assert.match(feedback.textContent, /invalid response/i, 'An invalid backend response did not show useful feedback.');

window.fetch = (_url, options) => new Promise((_resolve, reject) => {
    options.signal?.addEventListener('abort', () => reject({ name: 'AbortError' }), { once: true });
});
const slow = submit();
recoveryTimeout();
await slow.promise;
assert.equal(button.disabled, false, 'A timed-out request left the button disabled.');
assert.equal(button.textContent, 'Send Verification Code', 'A timed-out request kept the loading label.');
assert.match(feedback.textContent, /took too long/i, 'A timed-out request did not show useful feedback.');

button.disabled = true;
button.textContent = 'Sending...';
form.dataset.submitting = 'true';
pageshowHandler();
assert.equal(button.disabled, false, 'A restored page kept the recovery button disabled.');
assert.equal(button.textContent, 'Send Verification Code', 'A restored page kept the loading label.');

console.log('Forgot Password success, failure, invalid-response, timeout, duplicate-submit, redirect, and page-restore checks passed.');
