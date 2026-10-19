(() => {
    const button = document.querySelector('[data-email-code-send]');
    const countdown = document.querySelector('[data-email-code-countdown]');
    if (!button) return;

    const updateCooldown = () => {
        if (button.form?.dataset.submitting === 'true') return;
        const readyAt = Number(button.dataset.readyAt);
        const seconds = Number.isFinite(readyAt)
            ? Math.max(0, Math.ceil((readyAt - Date.now()) / 1000))
            : 0;
        button.disabled = seconds > 0;
        if (countdown) countdown.textContent = seconds > 0
            ? `You can request a code in ${seconds}s.`
            : '';
    };

    updateCooldown();
    window.setInterval(updateCooldown, 1000);
    window.addEventListener('pageshow', () => {
        if (button.form) {
            delete button.form.dataset.submitting;
            button.form.removeAttribute('aria-busy');
        }
        button.removeAttribute('aria-busy');
        updateCooldown();
    });
})();
