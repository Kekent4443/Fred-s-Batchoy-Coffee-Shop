document.addEventListener('DOMContentLoaded', () => {
    const method = document.getElementById('payment_method');
    if (!method) return;

    const panels = document.querySelectorAll('[data-payment-panel]');
    const gcashReference = document.querySelector('input[name="gcash_reference"]');
    const proof = document.querySelector('input[name="proof_of_payment"]');

    const refreshPaymentPanels = () => {
        panels.forEach((panel) => {
            panel.hidden = panel.dataset.paymentPanel !== method.value;
        });

        const needsGcash = method.value === 'gcash' && method.dataset.gcashAvailable === '1';
        if (gcashReference) gcashReference.required = needsGcash;
        if (proof) proof.required = needsGcash;
    };

    method.addEventListener('change', refreshPaymentPanels);
    refreshPaymentPanels();
});
