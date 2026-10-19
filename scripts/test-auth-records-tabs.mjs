import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../assets/js/auth-records.js', import.meta.url), 'utf8');
const pageUrl = new URL('http://127.0.0.1:8000/public/admin/auth-records.php?tab=login');
const nextUrl = new URL('http://127.0.0.1:8000/public/admin/auth-records.php?tab=failed#auth-records-tabs');
const state = { fetched: false, replaced: false, navigated: false, pushed: '', scrolledTo: -1, prevented: false };
const newTabs = { scrollLeft: 0 };
const nextPanel = {
    querySelector(selector) {
        if (selector === '[data-auth-records-tabs]') return newTabs;
        if (selector === '[aria-current="page"]') return { focus() {} };
        return null;
    },
};
const oldPanel = {
    setAttribute() {},
    removeAttribute() {},
    replaceWith(value) { state.replaced = value === nextPanel; },
};
const oldMetrics = { replaceWith() {} };
let clickHandler;
const section = {
    baseURI: pageUrl.href,
    querySelector(selector) {
        if (selector === '.auth-records__panel') return oldPanel;
        if (selector === '[data-auth-records-tabs]') return { scrollLeft: 24 };
        if (selector === '.auth-records__metrics') return oldMetrics;
        return null;
    },
    addEventListener(type, handler) { if (type === 'click') clickHandler = handler; },
};
class MockElement {
    closest() { return { href: nextUrl.href }; }
}
const context = {
    Element: MockElement,
    URL,
    AbortController,
    document: { querySelector: (selector) => selector === '.auth-records' ? section : null },
    window: {
        location: {
            href: pageUrl.href,
            origin: pageUrl.origin,
            pathname: pageUrl.pathname,
            assign() { state.navigated = true; },
        },
        scrollY: 480,
        scrollTo(options) { state.scrolledTo = options.top; },
        requestAnimationFrame(callback) { callback(); },
        history: { pushState(_data, _title, url) { state.pushed = url; } },
        addEventListener() {},
    },
    DOMParser: class {
        parseFromString() {
            return {
                querySelector(selector) {
                    return selector === '.auth-records__panel' ? nextPanel
                        : selector === '.auth-records__metrics' ? {} : null;
                },
            };
        }
    },
    fetch: async (url) => {
        state.fetched = true;
        return {
            ok: true,
            url,
            headers: { get: () => 'text/html; charset=UTF-8' },
            text: async () => '<!doctype html><title>Authentication Records</title>',
        };
    },
};

runInNewContext(source, context);
assert.equal(typeof clickHandler, 'function');
clickHandler({
    target: new MockElement(),
    button: 0,
    ctrlKey: false,
    metaKey: false,
    shiftKey: false,
    altKey: false,
    preventDefault() { state.prevented = true; },
});
await new Promise((resolve) => setImmediate(resolve));

assert.equal(state.prevented, true, 'Tab link should not perform native navigation.');
assert.equal(state.fetched, true, 'Tab content should be fetched in place.');
assert.equal(state.replaced, true, 'Only the records panel should be replaced.');
assert.equal(state.navigated, false, 'Successful tab switch should not reload the page.');
assert.equal(state.pushed, nextUrl.href.replace(nextUrl.hash, ''), 'Tab URL should update without a fragment jump.');
assert.equal(state.scrolledTo, 480, 'Scroll position should be preserved.');
assert.equal(newTabs.scrollLeft, 24, 'Horizontal tab position should be preserved.');
process.stdout.write('Authentication tabs switch in place without a page reload.\n');
