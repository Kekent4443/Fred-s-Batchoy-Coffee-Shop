/**
 * Shared theme-aware modal utility and declarative form confirmation handler.
 */
(function (root) {
    'use strict';

    const ICONS = {
        info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 11v5M12 8h.01"/></svg>',
        success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m8 12 3 3 5-5"/></svg>',
        warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg>',
        danger: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/></svg>',
    };

    let elements = null;
    let active = null;
    let lastFocused = null;
    let previousBodyOverflow = '';
    let closeSequence = 0;

    const normaliseVariant = (value) => {
        const variant = value === 'error' ? 'danger' : value;
        return Object.prototype.hasOwnProperty.call(ICONS, variant) ? variant : 'info';
    };

    const booleanOption = (value, fallback) => {
        if (value === undefined || value === null || value === '') return fallback;
        return value !== false && value !== 'false' && value !== '0';
    };

    function build() {
        if (elements) return elements;

        const backdrop = document.createElement('div');
        backdrop.className = 'system-modal-backdrop';
        backdrop.hidden = true;

        const dialog = document.createElement('section');
        dialog.className = 'system-modal';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', 'system-modal-title');
        dialog.setAttribute('aria-describedby', 'system-modal-message');
        dialog.tabIndex = -1;

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'system-modal__close';
        close.setAttribute('aria-label', 'Close dialog');
        close.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>';

        const icon = document.createElement('div');
        icon.className = 'system-modal__icon';
        const title = document.createElement('h2');
        title.id = 'system-modal-title';
        title.className = 'system-modal__title';
        const message = document.createElement('p');
        message.id = 'system-modal-message';
        message.className = 'system-modal__message';
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'system-modal__input';
        input.autocomplete = 'off';

        const actions = document.createElement('div');
        actions.className = 'system-modal__actions';
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'system-modal__btn system-modal__btn--cancel';
        const confirm = document.createElement('button');
        confirm.type = 'button';
        confirm.className = 'system-modal__btn system-modal__btn--ok';

        actions.append(cancel, confirm);
        dialog.append(close, icon, title, message, input, actions);
        backdrop.append(dialog);
        document.body.append(backdrop);

        close.addEventListener('click', () => finish(active?.type === 'prompt' ? null : false));
        cancel.addEventListener('click', () => finish(active?.type === 'prompt' ? null : false));
        confirm.addEventListener('click', confirmAction);
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                confirmAction();
            }
        });
        backdrop.addEventListener('mousedown', (event) => {
            if (event.target === backdrop && active?.closeOnBackdrop) {
                finish(active.type === 'prompt' ? null : false);
            }
        });

        elements = { backdrop, dialog, close, icon, title, message, input, cancel, confirm };
        return elements;
    }

    function defaultTitle(type, variant) {
        if (type === 'confirm') return 'Confirm Action';
        if (variant === 'success') return 'Success';
        if (variant === 'warning') return 'Warning';
        if (variant === 'danger') return 'Error';
        return 'Notice';
    }

    function focusableElements() {
        if (!elements) return [];
        return Array.from(elements.dialog.querySelectorAll('button:not([disabled]), input:not([disabled]), [href], [tabindex]:not([tabindex="-1"])'))
            .filter((item) => !item.hidden && item.offsetParent !== null);
    }

    function onKeydown(event) {
        if (!active) return;
        if (event.key === 'Escape') {
            if (active.closeOnEscape && !active.busy) {
                event.preventDefault();
                finish(active.type === 'prompt' ? null : false);
            }
            return;
        }
        if (event.key !== 'Tab') return;

        const focusable = focusableElements();
        if (!focusable.length) {
            event.preventDefault();
            elements.dialog.focus();
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function setBusy(isBusy) {
        if (!active || !elements) return;
        active.busy = isBusy;
        elements.confirm.disabled = isBusy;
        elements.cancel.disabled = isBusy;
        elements.close.disabled = isBusy;
        elements.dialog.setAttribute('aria-busy', isBusy ? 'true' : 'false');
        elements.confirm.textContent = isBusy ? active.processingLabel : active.confirmLabel;
    }

    async function confirmAction() {
        if (!active || active.busy) return;
        const value = active.type === 'prompt' ? elements.input.value : true;
        if (typeof active.onConfirm !== 'function') {
            if (active.busyOnConfirm) setBusy(true);
            finish(value);
            return;
        }

        setBusy(true);
        try {
            const result = await active.onConfirm(value);
            finish(result === undefined ? value : result);
        } catch (error) {
            setBusy(false);
            elements.message.textContent = error?.message || 'Unable to complete this action. Please try again.';
            elements.message.className = 'system-modal__message system-modal__message--danger';
            elements.confirm.focus();
        }
    }

    function finish(value) {
        if (!active || !elements) return;
        const resolve = active.resolve;
        active = null;
        elements.backdrop.classList.remove('is-open');
        document.removeEventListener('keydown', onKeydown, true);
        document.body.style.overflow = previousBodyOverflow;
        const sequence = ++closeSequence;
        window.setTimeout(() => {
            if (active || sequence !== closeSequence) return;
            elements.backdrop.hidden = true;
            elements.dialog.removeAttribute('aria-busy');
            elements.confirm.disabled = false;
            elements.cancel.disabled = false;
            elements.close.disabled = false;
        }, 180);
        if (lastFocused?.isConnected && typeof lastFocused.focus === 'function') {
            lastFocused.focus({ preventScroll: true });
        }
        lastFocused = null;
        resolve(value);
    }

    function showSystemModal(options) {
        const settings = options || {};
        const type = ['alert', 'confirm', 'prompt'].includes(settings.type) ? settings.type : 'alert';
        const variant = normaliseVariant(settings.variant || (type === 'confirm' ? 'warning' : 'info'));
        const sensitive = booleanOption(settings.sensitive, false);
        const modal = build();
        if (active) finish(active.type === 'prompt' ? null : false);
        closeSequence += 1;

        lastFocused = document.activeElement;
        previousBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        modal.icon.className = `system-modal__icon system-modal__icon--${variant}`;
        modal.icon.innerHTML = ICONS[variant];
        modal.title.textContent = settings.title || defaultTitle(type, variant);
        modal.message.textContent = settings.message || '';
        modal.message.className = `system-modal__message${variant === 'danger' ? ' system-modal__message--danger' : ''}`;
        modal.input.hidden = type !== 'prompt';
        modal.input.value = settings.inputValue || '';
        modal.input.placeholder = settings.inputPlaceholder || '';
        modal.cancel.hidden = type === 'alert';
        modal.cancel.textContent = settings.cancelLabel || 'Cancel';
        modal.confirm.className = `system-modal__btn ${variant === 'danger' || settings.dangerous ? 'system-modal__btn--danger' : 'system-modal__btn--ok'}`;

        return new Promise((resolve) => {
            active = {
                resolve,
                type,
                busy: false,
                busyOnConfirm: booleanOption(settings.busyOnConfirm, false),
                closeOnBackdrop: booleanOption(settings.closeOnBackdrop, !sensitive),
                closeOnEscape: booleanOption(settings.closeOnEscape, !sensitive),
                confirmLabel: settings.confirmLabel || settings.okLabel || (type === 'confirm' ? 'Confirm' : 'OK'),
                processingLabel: settings.processingLabel || 'Processing...',
                onConfirm: settings.onConfirm,
            };
            modal.confirm.textContent = active.confirmLabel;
            modal.backdrop.hidden = false;
            requestAnimationFrame(() => modal.backdrop.classList.add('is-open'));
            document.addEventListener('keydown', onKeydown, true);
            requestAnimationFrame(() => (type === 'prompt' ? modal.input : modal.cancel.hidden ? modal.confirm : modal.cancel).focus());
        });
    }

    function systemAlert(message, options) {
        return showSystemModal(Object.assign({ type: 'alert', message }, options || {}));
    }
    function systemConfirm(message, options) {
        return showSystemModal(Object.assign({ type: 'confirm', message }, options || {}));
    }
    function systemPrompt(message, defaultValue, options) {
        return showSystemModal(Object.assign({ type: 'prompt', message, inputValue: defaultValue || '' }, options || {}));
    }

    function inferredVariant(form) {
        const text = `${form.dataset.confirmMessage || ''} ${form.dataset.confirmLabel || ''}`.toLowerCase();
        if (/delete|remove|reject|cancel|deactivate|disable|permanent|cannot be undone/.test(text)) return 'danger';
        if (/approve|activate|restore|recover|verify|accept/.test(text)) return 'warning';
        return 'info';
    }

    document.addEventListener('submit', async (event) => {
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
        const source = submitter?.dataset.confirmMessage ? submitter : form;
        if (!form || !source?.dataset.confirmMessage) return;
        if (form.dataset.systemConfirmBypass === 'true') {
            delete form.dataset.systemConfirmBypass;
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        const variant = source.dataset.confirmVariant || inferredVariant(source);
        const sensitive = booleanOption(source.dataset.confirmSensitive, variant === 'danger');
        const confirmed = await systemConfirm(source.dataset.confirmMessage, {
            title: source.dataset.confirmTitle || 'Confirm Action',
            confirmLabel: source.dataset.confirmLabel || 'Confirm',
            cancelLabel: source.dataset.cancelLabel || 'Cancel',
            processingLabel: source.dataset.processingLabel || 'Processing...',
            variant,
            dangerous: source.dataset.confirmDangerous === 'true' || variant === 'danger',
            sensitive,
            closeOnBackdrop: booleanOption(source.dataset.confirmBackdropClose, !sensitive),
            closeOnEscape: booleanOption(source.dataset.confirmEscapeClose, !sensitive),
            busyOnConfirm: true,
        });
        if (!confirmed) return;

        form.dataset.systemConfirmBypass = 'true';
        if (typeof form.requestSubmit === 'function') form.requestSubmit(submitter);
        else form.submit();
    }, true);

    root.showSystemModal = showSystemModal;
    root.systemAlert = systemAlert;
    root.systemConfirm = systemConfirm;
    root.systemPrompt = systemPrompt;
})(window);
