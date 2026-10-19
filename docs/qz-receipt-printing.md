# QZ Tray receipt printing deployment

The receipt buttons use the existing 58mm text layout and send RAW text directly to the Windows printer named `Xprinter XP-58`. The old browser print/PDF flow is not used by those buttons. Admin and Manager currently have no Print Receipt action; this change does not grant them one.

## Required before printing

1. Install and run QZ Tray 2.3 on **each Windows computer attached to an XP-58**. Confirm the Windows printer is named exactly `Xprinter XP-58` and accepts RAW text. Do not select a different printer silently.
2. Obtain a QZ Tray-trusted signing certificate and matching 2048-bit private key. For production, use a QZ-issued certificate or provision a trusted custom root on every printing computer. QZ's per-installation demo certificate is only appropriate for testing that installation. A normal website TLS certificate is not automatically trusted by QZ Tray for signing.
3. Put the certificate and private key on the **PHP server**. The private key must be outside the entire project directory and readable only by the PHP service account. Configure these PHP process environment variables:

   - `QZ_TRAY_CERTIFICATE_PATH`: absolute path to the PEM-format QZ signing certificate.
   - `QZ_TRAY_PRIVATE_KEY_PATH`: absolute path to its matching PEM private key.
   - `QZ_TRAY_PRIVATE_KEY_PASSPHRASE`: only if the key is encrypted.

   Do not commit the key, copy it into JavaScript, place it under the web root, or send it in chat. The certificate endpoint exposes **only the public certificate** to an authenticated receipt-printing session; signing occurs server-side.
4. On the printing computer, allow/trust the site certificate in QZ Tray. Confirm its local secure WebSocket is reachable on one of ports 8181, 8282, 8383, or 8484. The website connects only to `wss://localhost` and does not require opening an inbound port on the hosted server.
5. Keep the PHP, JS, and CSS together in the local project. Test from localhost with the attached printer, browser, and order. The website must return a successful receipt data response, load the certificate, sign QZ requests, find the exact printer name, and send the RAW job. A queued-job acknowledgement is **not** proof of a clean physical print.

Without the matching trusted certificate/key, receipt buttons fail closed with a configuration message. There is no unsigned or browser-print fallback.

## Smoke tests

Run `php scripts/test-qz-receipt.php`, `node scripts/test-qz-receipt.js`, and `php scripts/test-delivery-tracking.php` in a disposable/local database environment. Also physically print short and long receipts, including long names/addresses, multiple products, totals, and repeated clicks. Verify the XP-58 receives one job per click and no horizontal faded bands. If QZ is stopped or the XP-58 is disconnected, the button must re-enable and show an error.

QZ's [signing guide](https://qz.io/docs/signing) describes its certificate and SHA512 signing requirements. Its [raw printing guide](https://qz.io/docs/raw) covers direct text jobs.
