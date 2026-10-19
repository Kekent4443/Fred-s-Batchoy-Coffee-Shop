(() => {
    'use strict';

    const normalize = (value) => value.trim().replace(/\s+/g, ' ');
    const deferSubmissionFeedback = window.queueMicrotask
        || ((callback) => Promise.resolve().then(callback));

    const setSubmissionFeedback = (form, submit, loading, readyLabel) => {
        if (!(submit instanceof HTMLButtonElement)) {
            return;
        }

        if (!submit.dataset.originalLabel) {
            submit.dataset.originalLabel = submit.textContent || readyLabel;
        }

        submit.disabled = loading;
        submit.setAttribute('aria-busy', loading ? 'true' : 'false');
        submit.textContent = loading ? 'Processing...' : submit.dataset.originalLabel;
        form.toggleAttribute('aria-busy', loading);
    };

    const initializeSubmissionFeedback = (form, submit, readyLabel) => {
        setSubmissionFeedback(form, submit, false, readyLabel);

        form.addEventListener('submit', (event) => {
            if (!form.checkValidity()) {
                setSubmissionFeedback(form, submit, false, readyLabel);
                return;
            }

            // A preceding handler may have rejected a repeat submission. Keep
            // the existing pending state while the first request is leaving.
            if (event.defaultPrevented) {
                return;
            }

            // app.js owns the shared data-submitting lock. Updating that flag
            // here caused this first valid submit to be mistaken for a repeat.
            deferSubmissionFeedback(() => {
                setSubmissionFeedback(form, submit, !event.defaultPrevented, readyLabel);
            });
        });

        window.addEventListener('pageshow', () => {
            delete form.dataset.submitting;
            setSubmissionFeedback(form, submit, false, readyLabel);
        });
    };

    const initializeCheckoutForm = (form) => {
        const fields = ['delivery_street', 'delivery_barangay', 'delivery_city', 'delivery_province']
            .map((id) => document.getElementById(id))
            .filter(Boolean);
        const landmark = document.getElementById('delivery_landmark');
        const preview = form.querySelector('[data-delivery-address-preview-text]');
        const landmarkRow = form.querySelector('[data-delivery-landmark-preview]');
        const landmarkPreview = form.querySelector('[data-delivery-landmark-preview-text]');
        const submit = form.querySelector('[data-checkout-submit]');

        const composeAddress = () => {
            const seen = new Set();

            return fields
                .map((field) => normalize(field.value))
                .filter((value) => {
                    const key = value.toLowerCase();
                    if (value === '' || seen.has(key)) {
                        return false;
                    }
                    seen.add(key);
                    return true;
                })
                .join(', ');
        };

        const refreshPreview = () => {
            const address = composeAddress();

            if (preview) {
                preview.textContent = address || 'Complete the required address fields to preview your delivery address.';
            }

            if (landmarkRow && landmarkPreview && landmark) {
                const landmarkValue = normalize(landmark.value);
                landmarkPreview.textContent = landmarkValue;
                landmarkRow.hidden = landmarkValue === '';
            }
        };

        [...fields, landmark].filter(Boolean).forEach((field) => {
            field.addEventListener('input', refreshPreview);
            field.addEventListener('change', refreshPreview);
        });
        refreshPreview();

        initializeSubmissionFeedback(form, submit, 'Continue to Payment');
    };

    const initializeOrderSubmissionForm = (form) => {
        const submit = form.querySelector('[data-order-submit]');

        initializeSubmissionFeedback(form, submit, 'Complete Payment');
    };

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-checkout-form]').forEach(initializeCheckoutForm);
        document.querySelectorAll('[data-order-submission-form]').forEach(initializeOrderSubmissionForm);
    });
})();
