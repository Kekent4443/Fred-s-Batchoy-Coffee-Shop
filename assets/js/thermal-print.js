(() => {
    'use strict';

    const root = document.documentElement;
    const receipt = document.querySelector('[data-thermal-receipt]');
    const pageStyle = document.querySelector('[data-thermal-page-style]');
    const printButton = document.getElementById('printReceiptButton');

    if (!(receipt instanceof HTMLElement) || !(pageStyle instanceof HTMLStyleElement)) {
        return;
    }

    const normalizePaperWidth = (value) => value === '80mm' || value === '80' ? '80mm' : '58mm';

    const prepareThermalPage = () => {
        const paperWidth = normalizePaperWidth(receipt.dataset.paperWidth);
        const size = paperWidth === '80mm' ? '80' : '58';
        root.dataset.thermalSize = size;
        receipt.dataset.paperWidth = paperWidth;
        receipt.classList.toggle('paper-58', size === '58');
        receipt.classList.toggle('paper-80', size === '80');
        receipt.querySelectorAll('[data-thermal-text-preview]').forEach((preview) => {
            if (!(preview instanceof HTMLElement)) return;
            const active = preview.dataset.thermalTextPreview === paperWidth;
            preview.hidden = !active;
            preview.setAttribute('aria-hidden', active ? 'false' : 'true');
        });

        // Use the receipt's actual laid-out height, with only 1mm for print rounding.
        // Measuring to an exact tenth can otherwise create an almost-empty extra page.
        const heightInMillimetres = Math.max(receipt.scrollHeight, receipt.getBoundingClientRect().height) * 25.4 / 96;
        const pageHeight = Math.max(10, Math.ceil((heightInMillimetres + 1) * 10) / 10);
        pageStyle.textContent = `@page { size: ${paperWidth} ${pageHeight}mm; margin: 0; }`;
    };

    window.addEventListener('beforeprint', prepareThermalPage);
    window.addEventListener('load', () => {
        prepareThermalPage();
        if (root.hasAttribute('data-thermal-auto-print')) {
            window.requestAnimationFrame(() => window.print());
        }
    }, { once: true });
    if (printButton instanceof HTMLButtonElement) {
        printButton.addEventListener('click', () => {
            prepareThermalPage();
            window.print();
        });
    }
})();
