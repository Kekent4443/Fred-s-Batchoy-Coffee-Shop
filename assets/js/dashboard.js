(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-dashboard-period]').forEach((select) => {
            const form = select.closest('form');
            const custom = form?.querySelector('[data-dashboard-custom-range]');
            if (!custom) return;
            const sync = () => { custom.hidden = select.value !== 'custom'; };
            select.addEventListener('change', sync);
            sync();
        });
    });
})();
