(() => {
    const resendButton = document.querySelector('[data-resend-button]');
    const expiryLabel = document.querySelector('[data-code-expiry]');
    const verifyButton = document.querySelector('[data-verify-submit]');
    const codeInput = document.querySelector('#verification-code');

    const updateTimers = () => {
        const now = Date.now();

        if (resendButton) {
            const readyAt = Number(resendButton.dataset.resendReadyAt);
            if (Number.isFinite(readyAt)) {
                const seconds = Math.max(0, Math.ceil((readyAt - now) / 1000));
                resendButton.disabled = seconds > 0;
                resendButton.textContent = seconds > 0
                    ? `Resend Code in ${seconds}s`
                    : (resendButton.dataset.readyLabel || 'Resend Code');
            }
        }

        if (expiryLabel) {
            const expiresAt = Number(expiryLabel.dataset.codeExpiresAt);
            if (Number.isFinite(expiresAt)) {
                const seconds = Math.max(0, Math.ceil((expiresAt - now) / 1000));
                expiryLabel.textContent = seconds > 0
                    ? `Verification session ends in ${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}.`
                    : 'Verification session expired. Request a new code.';
                if (verifyButton) verifyButton.disabled = seconds === 0;
                if (codeInput) {
                    if (seconds === 0) codeInput.setAttribute('aria-invalid', 'true');
                    else codeInput.removeAttribute('aria-invalid');
                }
            }
        }
    };

    updateTimers();
    if (resendButton || expiryLabel) window.setInterval(updateTimers, 1000);
    window.addEventListener('pageshow', updateTimers);
})();
