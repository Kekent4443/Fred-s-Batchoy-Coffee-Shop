import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { mkdtemp, rm } from 'node:fs/promises';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const artifactDirectory = process.argv[2] ? resolve(process.argv[2]) : '';
if (!artifactDirectory) {
    throw new Error('Usage: node scripts/test-product-responsive.mjs <artifact-directory>');
}

const edgeCandidates = [
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
];
const edge = edgeCandidates.find(existsSync);
if (!edge) {
    throw new Error('Microsoft Edge is required for responsive rendering tests.');
}

const pages = ['admin-products.html', 'manager-products.html'];
for (const page of pages) {
    if (!existsSync(join(artifactDirectory, page))) {
        throw new Error(`Missing rendered test artifact: ${page}`);
    }
}

const port = await new Promise((resolvePort, reject) => {
    const server = createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
        const address = server.address();
        const selected = typeof address === 'object' && address ? address.port : 0;
        server.close((error) => error ? reject(error) : resolvePort(selected));
    });
});
const profile = await mkdtemp(join(tmpdir(), 'freds-product-responsive-'));
const browser = spawn(edge, [
    '--headless=new',
    '--disable-gpu',
    '--hide-scrollbars',
    '--allow-file-access-from-files',
    '--no-first-run',
    `--remote-debugging-port=${port}`,
    `--user-data-dir=${profile}`,
    'about:blank',
], { stdio: 'ignore' });

const delay = (milliseconds) => new Promise((resolveDelay) => setTimeout(resolveDelay, milliseconds));
async function devtoolsJson(path) {
    let lastError;
    for (let attempt = 0; attempt < 60; attempt += 1) {
        try {
            const response = await fetch(`http://127.0.0.1:${port}${path}`);
            if (response.ok) return await response.json();
            lastError = new Error(`DevTools returned HTTP ${response.status}.`);
        } catch (error) {
            lastError = error;
        }
        await delay(100);
    }
    throw lastError ?? new Error('Unable to connect to the Edge DevTools endpoint.');
}

class CdpClient {
    constructor(url) {
        this.socket = new WebSocket(url);
        this.nextId = 1;
        this.pending = new Map();
        this.listeners = new Map();
        this.socket.addEventListener('message', (event) => {
            const message = JSON.parse(String(event.data));
            if (message.id && this.pending.has(message.id)) {
                const { resolveRequest, rejectRequest } = this.pending.get(message.id);
                this.pending.delete(message.id);
                if (message.error) rejectRequest(new Error(message.error.message));
                else resolveRequest(message.result ?? {});
                return;
            }
            const listeners = this.listeners.get(message.method) ?? [];
            listeners.splice(0).forEach((resolveEvent) => resolveEvent(message.params ?? {}));
        });
    }

    async open() {
        if (this.socket.readyState === WebSocket.OPEN) return;
        await new Promise((resolveOpen, rejectOpen) => {
            this.socket.addEventListener('open', resolveOpen, { once: true });
            this.socket.addEventListener('error', rejectOpen, { once: true });
        });
    }

    request(method, params = {}) {
        const id = this.nextId++;
        return new Promise((resolveRequest, rejectRequest) => {
            this.pending.set(id, { resolveRequest, rejectRequest });
            this.socket.send(JSON.stringify({ id, method, params }));
        });
    }

    once(method) {
        return new Promise((resolveEvent) => {
            const listeners = this.listeners.get(method) ?? [];
            listeners.push(resolveEvent);
            this.listeners.set(method, listeners);
        });
    }

    close() {
        this.socket.close();
    }
}

const widths = [320, 375, 390, 430, 768, 1440];
const results = [];
try {
    const targets = await devtoolsJson('/json/list');
    const target = targets.find((item) => item.type === 'page' && item.webSocketDebuggerUrl);
    if (!target) throw new Error('Edge did not expose a page target.');
    const client = new CdpClient(target.webSocketDebuggerUrl);
    await client.open();
    await client.request('Page.enable');
    await client.request('Runtime.enable');

    for (const page of pages) {
        for (const width of widths) {
            await client.request('Emulation.setDeviceMetricsOverride', {
                width,
                height: 1000,
                deviceScaleFactor: 1,
                // CSS viewport testing does not require mobile browser chrome;
                // keeping desktop emulation allows the exact 320px boundary.
                mobile: false,
            });
            const loaded = client.once('Page.loadEventFired');
            await client.request('Page.navigate', { url: pathToFileURL(join(artifactDirectory, page)).href });
            await loaded;
            const evaluation = await client.request('Runtime.evaluate', {
                returnByValue: true,
                expression: `(() => {
                    const viewportWidth = window.innerWidth;
                    const root = document.documentElement;
                    const tested = Array.from(document.querySelectorAll(
                        '.product-management, .section__heading, .metric-grid, .page-panel, .products-toolbar, .product-management__form'
                    ));
                    const boundsFailures = tested.flatMap((element) => {
                        const rect = element.getBoundingClientRect();
                        return rect.left < -1 || rect.right > viewportWidth + 1
                            ? [element.className || element.tagName]
                            : [];
                    });
                    const clippedText = Array.from(document.querySelectorAll(
                        '.page-header__copy, .product-management__panel-heading, .product-management__form label'
                    )).flatMap((element) => element.scrollWidth > element.clientWidth + 1
                        ? [element.className || element.tagName]
                        : []);
                    const controls = Array.from(document.querySelectorAll(
                        '.products-toolbar input, .products-toolbar select, .products-toolbar .btn, .product-management__form input, .product-management__form select, .product-management__form textarea, .product-management__form .btn'
                    )).filter((element) => element.type !== 'hidden');
                    const narrowControls = controls.flatMap((element) => {
                        const rect = element.getBoundingClientRect();
                        return rect.width < 40 || rect.left < -1 || rect.right > viewportWidth + 1
                            ? [element.name || element.textContent.trim() || element.tagName]
                            : [];
                    });
                    return {
                        innerWidth: viewportWidth,
                        scrollWidth: root.scrollWidth,
                        boundsFailures,
                        clippedText,
                        narrowControls,
                        overflowElements: Array.from(document.querySelectorAll('body *')).flatMap((element) => {
                            const rect = element.getBoundingClientRect();
                            return rect.right > viewportWidth + 1
                                ? [{ tag: element.tagName, className: String(element.className || ''), left: rect.left, right: rect.right, width: rect.width }]
                                : [];
                        }).slice(0, 12),
                    };
                })()`,
            });
            const metrics = evaluation.result?.value;
            if (!metrics) throw new Error(`No layout metrics returned for ${page} at ${width}px.`);
            if (metrics.innerWidth !== width) {
                throw new Error(`Viewport emulation failed for ${page}: expected ${width}px, got ${metrics.innerWidth}px.`);
            }
            if (metrics.scrollWidth > metrics.innerWidth + 1
                || metrics.boundsFailures.length
                || metrics.clippedText.length
                || metrics.narrowControls.length) {
                throw new Error(`${page} failed at ${width}px: ${JSON.stringify(metrics)}`);
            }
            results.push(`${page}\t${width}px\tpass`);
        }
    }
    client.close();
    console.log(results.join('\n'));
} finally {
    browser.kill();
    await new Promise((resolveExit) => browser.once('exit', resolveExit));
    await rm(profile, { recursive: true, force: true });
}
