(() => {
    const forms = Array.from(document.querySelectorAll('[data-password-recovery-form]'));
    const submissionTimers = new WeakMap();
    const requestTimeoutMs = 30000;
    const feedback = document.querySelector('[data-password-recovery-feedback]');

    const showFeedback = (message) => {
        if (!feedback) return;
        feedback.textContent = message;
        feedback.hidden = false;
    };

    const clearFeedback = () => {
        if (!feedback) return;
        feedback.textContent = '';
        feedback.hidden = true;
    };

    const resetFormState = (form) => {
        const timer = submissionTimers.get(form);
        if (timer) clearTimeout(timer);
        submissionTimers.delete(form);

        delete form.dataset.submitting;
        form.removeAttribute('aria-busy');

        const submitButton = form.querySelector('button[type="submit"]');
        if (!submitButton) return;

        delete submitButton.dataset.submitting;
        submitButton.removeAttribute('aria-busy');
        submitButton.textContent = submitButton.dataset.recoveryReadyLabel || 'Submit';

        const resendReadyAt = Number(submitButton.dataset.resendReadyAt);
        if (submitButton.matches('[data-resend-button]') && Number.isFinite(resendReadyAt)) {
            const seconds = Math.max(0, Math.ceil((resendReadyAt - Date.now()) / 1000));
            submitButton.disabled = seconds > 0;
            submitButton.textContent = seconds > 0
                ? `Resend Code in ${seconds}s`
                : submitButton.dataset.readyLabel;
            return;
        }

        if (submitButton.matches('[data-verify-submit]')) {
            const expiry = document.querySelector('[data-code-expiry]');
            const expiresAt = Number(expiry?.dataset.codeExpiresAt);
            submitButton.disabled = Number.isFinite(expiresAt) && expiresAt <= Date.now();
            return;
        }

        submitButton.disabled = false;
    };

    forms.forEach((form) => {
        const initialButton = form.querySelector('button[type="submit"]');
        if (initialButton) {
            initialButton.dataset.recoveryReadyLabel = initialButton.dataset.readyLabel
                || initialButton.textContent.trim();
        }

        form.addEventListener('submit', async (event) => {
            if (!form.checkValidity() || event.defaultPrevented) return;
            event.preventDefault();

            if (form.dataset.submitting === 'true') {
                return;
            }

            clearFeedback();
            form.dataset.submitting = 'true';
            form.setAttribute('aria-busy', 'true');
            const submitButton = event.submitter instanceof HTMLButtonElement
                ? event.submitter
                : form.querySelector('button[type="submit"]');
            if (submitButton) {
                submitButton.dataset.submitting = 'true';
                submitButton.setAttribute('aria-busy', 'true');
                submitButton.textContent = submitButton.dataset.loadingLabel || 'Please wait...';
                submitButton.disabled = true;
            }

            if (typeof window.fetch !== 'function' || typeof window.FormData !== 'function') {
                // Progressive fallback for obsolete browsers. Restore the
                // submitter first so it remains part of native form handling.
                resetFormState(form);
                form.submit();
                return;
            }

            const controller = typeof AbortController === 'function' ? new AbortController() : null;
            let timeout;
            const timeoutFailure = new Promise((_, reject) => {
                timeout = setTimeout(() => {
                    controller?.abort();
                    reject({ name: 'AbortError' });
                }, requestTimeoutMs);
            });
            submissionTimers.set(form, timeout);

            try {
                const response = await Promise.race([
                    window.fetch(form.action || window.location.href, {
                        method: 'POST',
                        body: new window.FormData(form),
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        signal: controller?.signal,
                    }),
                    timeoutFailure,
                ]);

                let payload = null;
                try {
                    payload = await response.json();
                } catch (_) {
                    payload = null;
                }

                if (!payload || typeof payload !== 'object') {
                    showFeedback('The server returned an invalid response. Please try again.');
                    return;
                }

                if (!response.ok || payload.success !== true) {
                    showFeedback(payload.message || 'Password recovery could not be completed. Please try again.');
                    return;
                }

                if (payload.redirect) {
                    window.location.assign(payload.redirect);
                    return;
                }

                showFeedback(payload.message || 'Request completed.');
            } catch (error) {
                const timedOut = error?.name === 'AbortError';
                showFeedback(timedOut
                    ? 'The request took too long. Check your connection and try again.'
                    : 'Unable to reach the server. Check your connection and try again.');
            } finally {
                clearTimeout(timeout);
                resetFormState(form);
            }
        });
    });

    // Browsers may restore the exact disabled/loading DOM from their back-forward
    // cache. Always return recovery forms to a usable state when shown again.
    window.addEventListener('pageshow', () => {
        forms.forEach(resetFormState);
    });

    const button = document.querySelector('[data-resend-button]');
    if (button) {
        const readyAt = Number(button.dataset.resendReadyAt);
        if (Number.isFinite(readyAt)) {
            let resendTimer;
            const updateButton = () => {
                if (button.dataset.submitting === 'true') return;
                const seconds = Math.max(0, Math.ceil((readyAt - Date.now()) / 1000));
                button.disabled = seconds > 0;
                button.textContent = seconds > 0 ? `Resend Code in ${seconds}s` : button.dataset.readyLabel;
                if (seconds === 0 && resendTimer) clearInterval(resendTimer);
            };
            updateButton();
            if (button.disabled) resendTimer = setInterval(updateButton, 250);
        }
    }

    const expiry = document.querySelector('[data-code-expiry]');
    if (!expiry) return;

    const expiresAt = Number(expiry.dataset.codeExpiresAt);
    if (!Number.isFinite(expiresAt)) return;

    const verifyButton = document.querySelector('[data-verify-submit]');
    const codeInput = document.querySelector('#verification-code');
    let expiryTimer;
    const updateExpiry = () => {
        const seconds = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
        const minutes = Math.floor(seconds / 60);
        const remainingSeconds = String(seconds % 60).padStart(2, '0');
        expiry.textContent = seconds > 0
            ? `Verification session ends in ${minutes}:${remainingSeconds}.`
            : 'Verification session expired. Request a new code.';

        if (seconds === 0) {
            if (verifyButton && verifyButton.dataset.submitting !== 'true') verifyButton.disabled = true;
            if (codeInput) codeInput.setAttribute('aria-invalid', 'true');
            if (expiryTimer) clearInterval(expiryTimer);
        }
    };
    updateExpiry();
    if (expiresAt > Date.now()) expiryTimer = setInterval(updateExpiry, 1000);
})();
