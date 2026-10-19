// Run with: node scripts/test-qz-receipt.js
// Tests the shared browser control without a live printer or signing key.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../assets/js/qz-receipt.js'), 'utf8');
const ticket = 'a'.repeat(48);
const receipt = { success: true, printer: 'Xprinter XP-58', paper: '58mm', data: 'FREDS\r\nTOTAL PHP 60.00\r\n', ticket };

function harness({ response = receipt, printers = ['Xprinter XP-58'], connectError = null, qzMissing = false, holdPrint = false, invalidJson = false, certificateMissing = false, receiptNetworkError = false } = {}) {
    const events = { fetches: [], connects: 0, prints: [], signing: null };
    const status = { hidden: true, dataset: {}, setAttribute() {} };
    const config = { dataset: { csrfToken: 'csrf', receiptUrl: '/receipt-qz-data.php', certificateUrl: '/qz-certificate.php', signUrl: '/qz-sign.php' } };
    const button = { disabled: false, dataset: { orderId: '7' }, listeners: {}, setAttribute(name, value) { this[name] = value; }, hasAttribute() { return false; }, addEventListener(name, listener) { this.listeners[name] = listener; } };
    const disabledButton = { ...button, disabled: true, dataset: { orderId: '8' }, listeners: {}, setAttribute: button.setAttribute, addEventListener: button.addEventListener };
    let active = false;
    let finishPrint;
    const qz = qzMissing ? undefined : {
        security: {
            setCertificatePromise(callback) { events.certificateCallback = callback; },
            setSignatureAlgorithm(value) { events.algorithm = value; },
            setSignaturePromise(callback) { events.signing = callback; }
        },
        websocket: {
            isActive() { return active; },
            async connect(options) { events.connects++; events.connectOptions = options; if (connectError) throw new Error(connectError); active = true; }
        },
        printers: { async find() { return printers; } },
        configs: { create(name, options) { return { name, options }; } },
        print(configured, data) {
            events.prints.push({ configured, data });
            if (holdPrint) return new Promise((resolve) => { finishPrint = resolve; });
            return Promise.resolve();
        }
    };
    const fetch = async (url, options) => {
        events.fetches.push({ url, options });
        if (url === config.dataset.certificateUrl) return { ok: !certificateMissing, async text() { return '-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----'; } };
        if (url === config.dataset.receiptUrl) {
            if (receiptNetworkError) throw new Error('Failed to fetch');
            return { ok: true, async json() { if (invalidJson) throw new SyntaxError('Bad JSON'); return response; } };
        }
        if (url === config.dataset.signUrl) return { ok: true, async text() { return 'signature'; } };
        throw new Error('Unexpected URL');
    };
    const context = {
        document: {
            querySelector(selector) { return selector === '[data-qz-receipt-config]' ? config : null; },
            querySelectorAll() { return [button, disabledButton]; },
            createElement() { return status; },
            body: { appendChild() {} }
        },
        window: { qz, clearTimeout, setTimeout, requestAnimationFrame() {} },
        fetch, AbortController, URLSearchParams, Promise, Error, Number, Map, Array
    };
    vm.runInNewContext(source, context, { filename: 'qz-receipt.js' });
    return { events, status, button, disabledButton, finishPrint: () => finishPrint?.(), tick: () => new Promise((resolve) => setTimeout(resolve, 5)) };
}

(async () => {
    const ok = harness();
    ok.button.listeners.click();
    ok.button.listeners.click();
    await ok.tick();
    assert.equal(ok.events.prints.length, 1, 'Double click must queue only one receipt');
    assert.equal(ok.events.connects, 1);
    assert.equal(ok.events.prints[0].configured.name, 'Xprinter XP-58');
    assert.equal(ok.events.prints[0].data[0].data, receipt.data);
    assert.equal(ok.events.algorithm, 'SHA512');
    assert.equal(ok.events.connectOptions.usingSecure, true);
    assert.equal(ok.button.disabled, false);
    assert.equal(ok.disabledButton.disabled, true, 'An initially disabled 80mm button must stay disabled');
    ok.button.listeners.click();
    await ok.tick();
    assert.equal(ok.events.connects, 1, 'The active QZ connection must be reused');
    assert.equal(ok.events.prints.length, 2);

    const pending = harness({ holdPrint: true });
    pending.button.listeners.click();
    await pending.tick();
    assert.equal(pending.button.disabled, true);
    pending.button.listeners.click();
    assert.equal(pending.events.prints.length, 1);
    pending.finishPrint();
    await pending.tick();
    assert.equal(pending.button.disabled, false);

    for (const [options, expected] of [
        [{ qzMissing: true }, 'QZ Tray printing is unavailable'],
        [{ printers: ['Office Laser Printer'] }, 'Xprinter XP-58 is not available'],
        [{ connectError: 'QZ_CONNECTION_TIMEOUT' }, 'QZ Tray is not running'],
        [{ response: { success: true, data: 'bad' } }, 'Unable to prepare the receipt'],
        [{ invalidJson: true }, 'invalid response'],
        [{ certificateMissing: true }, 'signing is not configured'],
        [{ receiptNetworkError: true }, 'Could not reach the receipt service']
    ]) {
        const failure = harness(options);
        failure.button.listeners.click();
        await failure.tick();
        assert.equal(failure.events.prints.length, 0);
        assert.equal(failure.button.disabled, false, 'Failure must re-enable the button');
        assert.equal(failure.status.dataset.kind, 'error');
        assert.ok(failure.status.textContent.includes(expected));
    }

    console.log('QZ receipt browser control: success, connection reuse, duplicate prevention, disabled 80mm, and failure recovery passed.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
