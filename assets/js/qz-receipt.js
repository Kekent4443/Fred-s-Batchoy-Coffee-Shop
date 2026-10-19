(() => {
    'use strict';

    const config = document.querySelector('[data-qz-receipt-config]');
    const buttons = [...document.querySelectorAll('[data-qz-receipt-print]')];
    if (!config || buttons.length === 0) return;
    const initiallyDisabled = new Map(buttons.map((button) => [button, button.disabled]));

    const qzClient = window.qz;
    const printerName = 'Xprinter XP-58';
    const csrfToken = config.dataset.csrfToken || '';
    let certificatePromise = null;
    let connectionPromise = null;
    let securityReady = false;
    let activeTicket = '';
    let busy = false;
    let messageTimer = 0;

    const status = document.createElement('div');
    status.className = 'qz-print-status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.hidden = true;
    document.body.appendChild(status);

    const showStatus = (message, kind = 'info') => {
        status.textContent = message;
        status.dataset.kind = kind;
        status.hidden = false;
        window.clearTimeout(messageTimer);
        if (kind === 'success') {
            messageTimer = window.setTimeout(() => { status.hidden = true; }, 7000);
        }
    };

    const withTimeout = (promise, milliseconds, code) => new Promise((resolve, reject) => {
        const timer = window.setTimeout(() => reject(new Error(code)), milliseconds);
        Promise.resolve(promise).then(
            (value) => { window.clearTimeout(timer); resolve(value); },
            (error) => { window.clearTimeout(timer); reject(error); }
        );
    });

    const request = async (url, fields, expectJson = true) => {
        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), 12000);
        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'Accept': expectJson ? 'application/json' : 'text/plain',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({ csrf_token: csrfToken, ...fields })
            });
            if (!response.ok) {
                if (expectJson) {
                    const error = await response.json().catch(() => null);
                    throw new Error(typeof error?.message === 'string' ? error.message : 'Unable to prepare the receipt. Please try again.');
                }
                throw new Error('SIGNING_FAILED');
            }
            if (!expectJson) return response.text();
            return response.json().catch(() => { throw new Error('INVALID_SERVER_RESPONSE'); });
        } finally {
            window.clearTimeout(timer);
        }
    };

    const certificate = () => {
        if (!certificatePromise) {
            const controller = new AbortController();
            const timer = window.setTimeout(() => controller.abort(), 12000);
            certificatePromise = fetch(config.dataset.certificateUrl, {
                credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { Accept: 'text/plain' }
            }).then((response) => {
                if (!response.ok) throw new Error('CERTIFICATE_MISSING');
                return response.text();
            }).then((value) => {
                if (!value.includes('BEGIN CERTIFICATE')) throw new Error('CERTIFICATE_INVALID');
                return value;
            }).catch((error) => {
                certificatePromise = null;
                throw error;
            }).finally(() => {
                window.clearTimeout(timer);
            });
        }
        return certificatePromise;
    };

    const setupSecurity = async () => {
        if (!qzClient?.security || !qzClient?.websocket) throw new Error('QZ_LIBRARY_MISSING');
        await certificate(); // Never fall back to unsigned production requests.
        if (securityReady) return;
        qzClient.security.setCertificatePromise((resolve, reject) => {
            certificate().then(resolve, reject);
        }, { rejectOnFailure: true });
        qzClient.security.setSignatureAlgorithm('SHA512');
        qzClient.security.setSignaturePromise((hash) => (resolve, reject) => {
            if (!activeTicket) {
                reject(new Error('PRINT_AUTHORIZATION_MISSING'));
                return;
            }
            request(config.dataset.signUrl, { hash, ticket: activeTicket }, false).then(resolve, reject);
        });
        securityReady = true;
    };

    const connect = async () => {
        if (qzClient.websocket.isActive()) return;
        if (!connectionPromise) {
            connectionPromise = withTimeout(qzClient.websocket.connect({
                host: 'localhost',
                usingSecure: true,
                port: { secure: [8181, 8282, 8383, 8484] },
                retries: 0
            }), 15000, 'QZ_CONNECTION_TIMEOUT').finally(() => { connectionPromise = null; });
        }
        await connectionPromise;
    };

    const findPrinter = async () => {
        const names = await withTimeout(qzClient.printers.find(), 10000, 'QZ_PRINTER_TIMEOUT');
        const exact = Array.isArray(names)
            ? names.find((name) => typeof name === 'string' && name.trim().toLowerCase() === printerName.toLowerCase())
            : null;
        if (!exact) throw new Error('XP58_NOT_FOUND');
        return exact;
    };

    const friendlyError = (error) => {
        const code = error instanceof Error ? error.message : '';
        if (error?.name === 'AbortError') return 'The print request timed out. Please check your connection and try again.';
        if (code === 'CERTIFICATE_MISSING' || code === 'CERTIFICATE_INVALID') {
            return 'QZ Tray signing is not configured yet. Please contact the administrator.';
        }
        if (code === 'INVALID_SERVER_RESPONSE') return 'The receipt service returned an invalid response. Please try again.';
        if (code === 'QZ_PRINT_TIMEOUT') return 'QZ Tray did not confirm printing. Check the printer queue before trying again to avoid a duplicate receipt.';
        if (code === 'QZ_LIBRARY_MISSING') return 'QZ Tray printing is unavailable. Refresh the page and try again.';
        if (code === 'XP58_NOT_FOUND' || code === 'QZ_PRINTER_TIMEOUT') return 'Xprinter XP-58 is not available. Connect it and try again.';
        if (code === 'QZ_CONNECTION_TIMEOUT' || /websocket|connection|connect/i.test(code)) {
            return 'QZ Tray is not running. Please start QZ Tray and try again.';
        }
        if (code === 'SIGNING_FAILED' || code === 'PRINT_AUTHORIZATION_MISSING') {
            return 'Unable to authorize QZ Tray printing. Please refresh the page and try again.';
        }
        if (code === 'Failed to fetch' || code === 'NetworkError when attempting to fetch resource.') {
            return 'Could not reach the receipt service. Check your connection and try again.';
        }
        const safeServerMessages = [
            'Unable to prepare the receipt. Please try again.',
            'QZ Tray printing is not configured yet. Please contact the administrator.',
            'Please wait before printing this receipt again.',
            'Receipt not found.',
            'Invalid CSRF token. Please refresh the page and try again.'
        ];
        return safeServerMessages.includes(code)
            ? code
            : 'Unable to print the receipt. Check QZ Tray and the XP-58, then try again.';
    };

    const setBusy = (value) => {
        busy = value;
        buttons.forEach((button) => {
            button.disabled = value || initiallyDisabled.get(button);
            button.setAttribute('aria-busy', value ? 'true' : 'false');
            if (button.dataset.actionLabel !== undefined) {
                button.dataset.actionLabel = value ? 'Printing Receipt' : 'Print Receipt';
            } else {
                button.textContent = value ? 'Printing...' : 'Print Receipt';
            }
        });
    };

    const printReceipt = async (button) => {
        if (busy || initiallyDisabled.get(button)) return;
        const orderId = Number.parseInt(button.dataset.orderId || '', 10);
        if (!Number.isSafeInteger(orderId) || orderId < 1) {
            showStatus('Receipt not found. Please refresh the page.', 'error');
            return;
        }

        setBusy(true);
        showStatus('Connecting to QZ Tray…');
        try {
            const receipt = await request(config.dataset.receiptUrl, { order_id: String(orderId) });
            if (receipt?.success !== true || receipt?.printer !== printerName
                || receipt?.paper !== '58mm' || typeof receipt?.data !== 'string'
                || !/^[a-f0-9]{48}$/.test(receipt?.ticket || '')) {
                throw new Error('Unable to prepare the receipt. Please try again.');
            }
            activeTicket = receipt.ticket;
            await setupSecurity();
            await connect();
            const printer = await findPrinter();
            const printConfig = qzClient.configs.create(printer, { jobName: 'Freds 58mm Receipt' });
            const data = [{ type: 'raw', format: 'command', flavor: 'plain', data: receipt.data }];
            await withTimeout(qzClient.print(printConfig, data), 20000, 'QZ_PRINT_TIMEOUT');
            showStatus('Receipt sent to Xprinter XP-58.', 'success');
        } catch (error) {
            showStatus(friendlyError(error), 'error');
        } finally {
            activeTicket = '';
            setBusy(false);
        }
    };

    buttons.forEach((button) => button.addEventListener('click', () => { void printReceipt(button); }));
    const autoPrint = buttons.find((button) => button.hasAttribute('data-qz-auto-print'));
    if (autoPrint) window.requestAnimationFrame(() => { void printReceipt(autoPrint); });
})();
