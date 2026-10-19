"use strict";

const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const { spawn } = require("node:child_process");

const artifactDirectory = process.argv[2];
if (!artifactDirectory) {
  throw new Error("Usage: node scripts/test-product-responsive.js <artifact-directory>");
}

const edgePath = process.env.EDGE_PATH || "C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe";
if (!fs.existsSync(edgePath)) {
  throw new Error("Microsoft Edge was not found. Set EDGE_PATH to a Chromium-compatible browser.");
}

const managerPath = path.join(artifactDirectory, "manager-products.html");
const adminPath = path.join(artifactDirectory, "admin-products.html");
for (const target of [managerPath, adminPath]) {
  if (!fs.existsSync(target)) throw new Error(`Missing rendered artifact: ${target}`);
}

const port = 9300 + Math.floor(Math.random() * 400);
const profileDirectory = fs.mkdtempSync(path.join(os.tmpdir(), "freds-product-responsive-"));
const browser = spawn(edgePath, [
  "--headless=new",
  "--disable-gpu",
  "--hide-scrollbars",
  "--allow-file-access-from-files",
  `--remote-debugging-port=${port}`,
  `--user-data-dir=${profileDirectory}`,
  "about:blank",
], { stdio: "ignore", windowsHide: true });

const delay = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

async function waitForDebugger() {
  for (let attempt = 0; attempt < 50; attempt += 1) {
    try {
      const response = await fetch(`http://127.0.0.1:${port}/json/version`);
      if (response.ok) return;
    } catch (_) {
      // The browser is still starting.
    }
    await delay(100);
  }
  throw new Error("Timed out waiting for the local browser debugger.");
}

async function createPage(url) {
  const response = await fetch(`http://127.0.0.1:${port}/json/new?${encodeURIComponent(url)}`, { method: "PUT" });
  if (!response.ok) throw new Error(`Unable to create browser page: HTTP ${response.status}`);
  return response.json();
}

function connect(webSocketUrl) {
  const socket = new WebSocket(webSocketUrl);
  const pending = new Map();
  let nextId = 1;
  socket.addEventListener("message", (event) => {
    const message = JSON.parse(String(event.data));
    if (!message.id || !pending.has(message.id)) return;
    const { resolve, reject } = pending.get(message.id);
    pending.delete(message.id);
    if (message.error) reject(new Error(message.error.message));
    else resolve(message.result || {});
  });
  const opened = new Promise((resolve, reject) => {
    socket.addEventListener("open", resolve, { once: true });
    socket.addEventListener("error", reject, { once: true });
  });
  return {
    async send(method, params = {}) {
      await opened;
      const id = nextId++;
      const result = new Promise((resolve, reject) => pending.set(id, { resolve, reject }));
      socket.send(JSON.stringify({ id, method, params }));
      return result;
    },
    close() { socket.close(); },
  };
}

function fileUrl(filePath) {
  return `file:///${filePath.replace(/\\/g, "/")}`;
}

const layoutExpression = `(() => {
  const viewport = window.innerWidth;
  const selectors = [
    '.product-management', '.page-header', '.product-management__metrics',
    '.page-panel', '.products-toolbar', '.product-management__form'
  ];
  const violations = [];
  for (const selector of selectors) {
    for (const element of document.querySelectorAll(selector)) {
      const rect = element.getBoundingClientRect();
      if (rect.left < -1 || rect.right > viewport + 1 || rect.width > viewport + 1) {
        violations.push({ selector, left: rect.left, right: rect.right, width: rect.width });
      }
    }
  }
  for (const element of document.querySelectorAll('.products-toolbar > *, .product-management__form > *')) {
    const rect = element.getBoundingClientRect();
    if (rect.left < -1 || rect.right > viewport + 1) {
      violations.push({ selector: element.tagName + '[name="' + (element.getAttribute('name') || '') + '"]', left: rect.left, right: rect.right, width: rect.width });
    }
  }
  const actionFailures = Array.from(document.querySelectorAll('.product-management__actions')).flatMap((actions) => {
    const labels = Array.from(actions.querySelectorAll('a.btn, button.btn')).map((control) =>
      (control.getAttribute('aria-label') || control.getAttribute('title') || '').trim()
    );
    const hasEdit = labels.some((label) => /^Edit\\b/i.test(label));
    const hasStatus = labels.some((label) => /^(?:Deactivate|Activate) Product$/i.test(label));
    const hasDelete = labels.some((label) => /^Delete Product$/i.test(label));
    const statusForm = actions.querySelector('form[data-confirm-message] input[name="action"][value="toggle_product_status"]');
    const deleteForm = actions.querySelector('form[data-confirm-message][data-confirm-sensitive="true"] input[name="action"][value="delete_product"]');
    return hasEdit && hasStatus && hasDelete && statusForm && deleteForm ? [] : [{ labels, hasEdit, hasStatus, hasDelete }];
  });
  const availabilityLabels = Array.from(document.querySelectorAll('td[data-label="Availability"]')).map((cell) => cell.textContent.trim());
  return {
    viewport,
    documentWidth: document.documentElement.scrollWidth,
    hasSharedPage: Boolean(document.querySelector('.product-management')),
    hasHorizontalTableContainer: getComputedStyle(document.querySelector('.product-management__table-wrap')).overflowX === 'auto',
    hasActionRows: document.querySelectorAll('.product-management__actions').length > 0,
    actionFailures,
    hasRemovedAvailabilityAction: Boolean(document.querySelector('form input[name="action"][value="update_availability"]')),
    hasInvalidAvailabilityLabel: availabilityLabels.some((label) => !['Available', 'Unavailable'].includes(label)),
    violations,
  };
})()`;

async function inspectPage(role, targetPath, width, client) {
  await client.send("Emulation.setDeviceMetricsOverride", {
    width,
    height: 1800,
    deviceScaleFactor: 1,
    mobile: true,
    screenWidth: width,
    screenHeight: 1800,
  });
  await client.send("Page.navigate", { url: fileUrl(targetPath) });
  await delay(350);
  const evaluation = await client.send("Runtime.evaluate", { expression: layoutExpression, returnByValue: true });
  const result = evaluation.result.value;
  const screenshot = await client.send("Page.captureScreenshot", { format: "png", fromSurface: true });
  fs.writeFileSync(path.join(artifactDirectory, `${role}-emulated-${width}.png`), Buffer.from(screenshot.data, "base64"));
  if (!result.hasSharedPage || !result.hasHorizontalTableContainer || !result.hasActionRows
    || result.actionFailures.length > 0 || result.hasRemovedAvailabilityAction
    || result.hasInvalidAvailabilityLabel || result.violations.length > 0) {
    throw new Error(`${role} ${width}px responsive check failed: ${JSON.stringify(result)}`);
  }
  return { role, width, ...result };
}

(async () => {
  try {
    await waitForDebugger();
    const page = await createPage("about:blank");
    const client = connect(page.webSocketDebuggerUrl);
    await client.send("Page.enable");
    await client.send("Runtime.enable");
    const results = [];
    for (const width of [320, 375, 390, 430, 768, 1440]) {
      results.push(await inspectPage("manager", managerPath, width, client));
      results.push(await inspectPage("admin", adminPath, width, client));
    }
    client.close();
    fs.writeFileSync(path.join(artifactDirectory, "responsive-results.json"), JSON.stringify(results, null, 2));
    process.stdout.write("Admin and Manager Product Management responsive checks passed at 320, 375, 390, 430, 768, and 1440px.\n");
  } finally {
    browser.kill();
    await delay(150);
    fs.rmSync(profileDirectory, { recursive: true, force: true });
  }
})().catch((error) => {
  process.stderr.write(`${error.stack || error}\n`);
  process.exitCode = 1;
});
