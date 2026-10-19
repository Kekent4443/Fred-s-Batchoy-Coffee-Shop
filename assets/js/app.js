document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-print-report]').forEach((button) => {
        button.addEventListener('click', () => window.print());
    });

    const themeStorageKey = 'freds-color-theme-v2';
    const root = document.documentElement;
    const themeButton = document.querySelector('[data-system-theme-toggle]');
    const themeSlot = document.querySelector('[data-system-theme-slot]');

    let passwordFieldCounter = 0;

    document.querySelectorAll('[data-bulk-discount-form]').forEach((form) => {
        const minimum = form.querySelector('[name="min_quantity"]');
        const maximum = form.querySelector('[name="max_quantity"]');
        if (!minimum || !maximum) return;

        const invalidRange = () => minimum.value.trim() !== '' && maximum.value.trim() !== ''
            && Number(maximum.value) <= Number(minimum.value);
        const updateRangeState = () => {
            if (invalidRange()) maximum.setAttribute('aria-invalid', 'true');
            else maximum.removeAttribute('aria-invalid');
        };
        let rangeAlertOpen = false;
        const showRangeAlert = () => {
            if (rangeAlertOpen) return;
            rangeAlertOpen = true;
            maximum.setAttribute('aria-invalid', 'true');
            const message = 'Maximum Quantity must be greater than Minimum Quantity.';
            if (typeof window.systemAlert === 'function') {
                window.systemAlert(message, {
                    title: 'Invalid Quantity Range',
                    variant: 'warning',
                    confirmLabel: 'Review Quantities',
                }).finally(() => {
                    rangeAlertOpen = false;
                    maximum.focus();
                });
            } else {
                window.alert(message);
                rangeAlertOpen = false;
                maximum.focus();
            }
        };

        minimum.addEventListener('input', updateRangeState);
        maximum.addEventListener('input', updateRangeState);
        // Intercept native validation when an equal range also violates min="2".
        maximum.addEventListener('invalid', (event) => {
            if (!invalidRange()) return;
            event.preventDefault();
            showRangeAlert();
        });
        form.addEventListener('submit', (event) => {
            if (!invalidRange()) return;
            event.preventDefault();
            showRangeAlert();
        });
    });

    const parseDisplayDate = (value) => {
        const match = String(value || '').trim().match(/^(\d{2})\/(\d{2})\/(\d{2}|\d{4})$/);
        if (!match) return '';

        const month = Number(match[1]);
        const day = Number(match[2]);
        const year = match[3].length === 4 ? Number(match[3]) : 2000 + Number(match[3]);
        const candidate = new Date(Date.UTC(year, month - 1, day));

        if (
            candidate.getUTCFullYear() !== year
            || candidate.getUTCMonth() !== month - 1
            || candidate.getUTCDate() !== day
        ) {
            return '';
        }

        return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    };

    const formatIsoDate = (value) => {
        const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!match) return '';

        const year = Number(match[1]);
        const month = Number(match[2]);
        const day = Number(match[3]);
        const candidate = new Date(Date.UTC(year, month - 1, day));

        if (
            candidate.getUTCFullYear() !== year
            || candidate.getUTCMonth() !== month - 1
            || candidate.getUTCDate() !== day
        ) {
            return '';
        }

        return `${match[2]}/${match[3]}/${year < 2000 || year > 2099 ? match[1] : match[1].slice(-2)}`;
    };

    const parseDisplayDateTime = (value) => {
        const match = String(value || '').trim().match(/^(\d{2})\/(\d{2})\/(\d{2}|\d{4})\s+(\d{1,2}):(\d{2})\s*(AM|PM)$/i);
        if (!match) return '';

        const month = Number(match[1]);
        const day = Number(match[2]);
        const year = match[3].length === 4 ? Number(match[3]) : 2000 + Number(match[3]);
        const hour12 = Number(match[4]);
        const minute = Number(match[5]);
        const meridiem = match[6].toUpperCase();
        const candidate = new Date(Date.UTC(year, month - 1, day));

        if (
            candidate.getUTCFullYear() !== year
            || candidate.getUTCMonth() !== month - 1
            || candidate.getUTCDate() !== day
            || hour12 < 1
            || hour12 > 12
            || minute < 0
            || minute > 59
        ) {
            return '';
        }

        const hour24 = (hour12 % 12) + (meridiem === 'PM' ? 12 : 0);
        return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}T${String(hour24).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
    };

    const formatIsoDateTime = (value) => {
        const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/);
        if (!match) return '';

        const year = Number(match[1]);
        const month = Number(match[2]);
        const day = Number(match[3]);
        const hour24 = Number(match[4]);
        const minute = Number(match[5]);
        const candidate = new Date(Date.UTC(year, month - 1, day));
        if (
            candidate.getUTCFullYear() !== year
            || candidate.getUTCMonth() !== month - 1
            || candidate.getUTCDate() !== day
            || hour24 > 23
            || minute > 59
        ) {
            return '';
        }

        const meridiem = hour24 >= 12 ? 'PM' : 'AM';
        const hour12 = hour24 % 12 || 12;
        const displayYear = year < 2000 || year > 2099 ? match[1] : match[1].slice(-2);
        return `${match[2]}/${match[3]}/${displayYear} ${String(hour12).padStart(2, '0')}:${match[5]} ${meridiem}`;
    };

    const setDateError = (display, message) => {
        display.setCustomValidity(message);
        display.setAttribute('aria-invalid', String(Boolean(message)));
        const error = display.closest('[data-date-input]').querySelector('[data-date-error]');
        if (error) {
            error.textContent = message;
            error.hidden = !message;
        }
    };

    const inputIsoDate = (display) => display.dataset.originalDisplay === display.value
        ? display.dataset.originalIso
        : parseDisplayDate(display.value);

    const initializeDateInput = (component) => {
        if (component.dataset.dateInitialized === 'true') return;
        const display = component.querySelector('[data-date-display]');
        const native = component.querySelector('[data-date-native]');
        const pickerButton = component.querySelector('[data-date-picker-button]');

        if (!(display instanceof HTMLInputElement) || !(native instanceof HTMLInputElement)) return;
        component.dataset.dateInitialized = 'true';

        const syncFromDisplay = (showError = false) => {
            const rawValue = display.value.trim();
            const isoValue = rawValue === '' ? '' : inputIsoDate(display);
            native.value = isoValue;
            setDateError(display, showError && rawValue !== '' && isoValue === ''
                ? 'Enter a valid date in MM/DD/YY format.'
                : '');
            return isoValue;
        };

        display.addEventListener('input', () => syncFromDisplay(false));
        display.addEventListener('blur', () => syncFromDisplay(true));

        native.addEventListener('change', () => {
            const formatted = formatIsoDate(native.value);
            if (formatted !== '' || native.value === '') {
                display.value = formatted;
                display.dataset.originalDisplay = formatted;
                display.dataset.originalIso = native.value;
                setDateError(display, '');
                display.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        if (pickerButton instanceof HTMLButtonElement) {
            pickerButton.addEventListener('click', () => {
                if (typeof native.showPicker === 'function') {
                    try {
                        native.showPicker();
                        return;
                    } catch (error) {
                        // Older browsers can expose showPicker() without allowing it here.
                    }
                }

                component.querySelector('.date-input-control').classList.add('is-picker-fallback');
                native.removeAttribute('aria-hidden');
                native.tabIndex = 0;
                native.setAttribute('aria-label', pickerButton.getAttribute('aria-label'));
                native.focus({ preventScroll: true });
                native.click();
            });
        }

        // Keep an existing ISO century intact until the user edits the field.
        if (native.value && formatIsoDate(native.value) === display.value) {
            display.dataset.originalDisplay = display.value;
            display.dataset.originalIso = native.value;
        }
        if (typeof native.showPicker !== 'function') {
            component.querySelector('.date-input-control').classList.add('is-picker-fallback');
            native.removeAttribute('aria-hidden');
            native.tabIndex = 0;
            native.setAttribute('aria-label', pickerButton.getAttribute('aria-label'));
            pickerButton.tabIndex = -1;
        }
    };

    document.querySelectorAll('[data-date-input]').forEach(initializeDateInput);
    document.addEventListener('app:date-inputs-added', (event) => {
        event.detail?.root?.querySelectorAll('[data-date-input]').forEach(initializeDateInput);
    });

    const setDateTimeError = (display, message) => {
        display.setCustomValidity(message);
        display.setAttribute('aria-invalid', String(Boolean(message)));
        const error = display.closest('[data-datetime-input]')?.querySelector('[data-datetime-error]');
        if (error) {
            error.textContent = message;
            error.hidden = !message;
        }
    };

    const initializeDateTimeInput = (component) => {
        const display = component.querySelector('[data-datetime-display]');
        const native = component.querySelector('[data-datetime-native]');
        const pickerButton = component.querySelector('[data-datetime-picker-button]');
        if (!(display instanceof HTMLInputElement) || !(native instanceof HTMLInputElement)) return;

        const syncFromDisplay = (showError = false) => {
            const rawValue = display.value.trim();
            const isoValue = rawValue === '' ? '' : parseDisplayDateTime(rawValue);
            native.value = isoValue;
            setDateTimeError(display, showError && rawValue !== '' && isoValue === ''
                ? 'Enter a valid date and time in MM/DD/YY hh:mm AM format.'
                : '');
            return isoValue;
        };

        display.addEventListener('input', () => syncFromDisplay(false));
        display.addEventListener('blur', () => syncFromDisplay(true));
        native.addEventListener('change', () => {
            const formatted = formatIsoDateTime(native.value);
            if (formatted !== '' || native.value === '') {
                display.value = formatted;
                setDateTimeError(display, '');
                display.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        if (pickerButton instanceof HTMLButtonElement) {
            pickerButton.addEventListener('click', () => {
                if (typeof native.showPicker === 'function') {
                    try {
                        native.showPicker();
                        return;
                    } catch (error) {
                        // Continue to the native fallback below.
                    }
                }

                component.querySelector('.date-input-control')?.classList.add('is-picker-fallback');
                native.removeAttribute('aria-hidden');
                native.tabIndex = 0;
                native.setAttribute('aria-label', pickerButton.getAttribute('aria-label') || 'Choose date and time');
                native.focus({ preventScroll: true });
                native.click();
            });
        }
    };

    document.querySelectorAll('[data-datetime-input]').forEach(initializeDateTimeInput);

    Array.from(document.forms).filter((form) => form.querySelector('[data-date-input]')).forEach((form) => {
        form.addEventListener('reset', () => {
            setTimeout(() => form.querySelectorAll('[data-date-display]').forEach((display) => {
                setDateError(display, '');
                display.dispatchEvent(new Event('input'));
            }), 0);
        });
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;

        const dateComponents = Array.from(form.querySelectorAll('[data-date-input]'));
        const dateTimeComponents = Array.from(form.querySelectorAll('[data-datetime-input]'));
        let firstInvalid = null;

        dateComponents.forEach((component) => {
            const display = component.querySelector('[data-date-display]');
            const native = component.querySelector('[data-date-native]');
            if (!(display instanceof HTMLInputElement) || !(native instanceof HTMLInputElement)) return;

            const rawValue = display.value.trim();
            const isoValue = rawValue === '' ? '' : inputIsoDate(display);
            native.value = isoValue;
            setDateError(display, rawValue !== '' && isoValue === ''
                ? 'Enter a valid date in MM/DD/YY format.'
                : '');

            if (!display.checkValidity() && firstInvalid === null) firstInvalid = display;
        });

        dateTimeComponents.forEach((component) => {
            const display = component.querySelector('[data-datetime-display]');
            const native = component.querySelector('[data-datetime-native]');
            if (!(display instanceof HTMLInputElement) || !(native instanceof HTMLInputElement)) return;

            const rawValue = display.value.trim();
            const isoValue = rawValue === '' ? '' : parseDisplayDateTime(rawValue);
            native.value = isoValue;
            setDateTimeError(display, rawValue !== '' && isoValue === ''
                ? 'Enter a valid date and time in MM/DD/YY hh:mm AM format.'
                : '');

            if (!display.checkValidity() && firstInvalid === null) firstInvalid = display;
        });

        const start = form.querySelector('[data-date-display][name="date_from"], [data-date-display][name="from"]');
        const end = form.querySelector('[data-date-display][name="date_to"], [data-date-display][name="to"]');
        if (start instanceof HTMLInputElement && end instanceof HTMLInputElement) {
            const startIso = inputIsoDate(start);
            const endIso = inputIsoDate(end);
            if (startIso !== '' && endIso !== '' && startIso > endIso) {
                setDateError(end, 'End date must be the same as or later than the start date.');
                if (firstInvalid === null) firstInvalid = end;
            }
        }

        if (firstInvalid instanceof HTMLInputElement) {
            event.preventDefault();

            firstInvalid.focus();
        }
    });

    const firstInvalidField = document.querySelector('[data-invalid-field]');
    if (firstInvalidField instanceof HTMLElement) {
        window.requestAnimationFrame(() => {
            firstInvalidField.focus({ preventScroll: true });
            firstInvalidField.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }

    const resetLogoutForm = (form) => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        delete form.dataset.logoutSubmitting;
        delete form.dataset.submitting;
        const button = form.querySelector('[data-logout-button]');

        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        button.disabled = false;
        button.setAttribute('aria-busy', 'false');
        button.setAttribute('aria-label', 'Log out');
        button.removeAttribute('aria-disabled');
        form.removeAttribute('aria-busy');

        const icon = button.querySelector('[data-logout-icon]');
        const spinner = button.querySelector('[data-logout-spinner]');
        const label = button.querySelector('[data-logout-label]');
        const status = button.querySelector('[data-logout-status]');

        if (icon) icon.hidden = false;
        if (spinner) spinner.hidden = true;
        if (label) label.textContent = label.dataset.readyLabel || 'Log out';
        if (status) status.textContent = 'Log out';
    };

    const deferLogoutButtonDisable = window.queueMicrotask
        || ((callback) => Promise.resolve().then(callback));

    document.addEventListener('submit', (event) => {
        const form = event.target instanceof Element
            ? event.target.closest('[data-logout-form]')
            : null;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const button = form.querySelector('[data-logout-button]');
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        if (form.dataset.logoutSubmitting === 'true' || button.disabled) {
            event.preventDefault();
            return;
        }

        if (event.defaultPrevented) {
            return;
        }

        form.dataset.logoutSubmitting = 'true';
        form.setAttribute('aria-busy', 'true');
        button.setAttribute('aria-busy', 'true');
        button.setAttribute('aria-disabled', 'true');
        button.setAttribute('aria-label', 'Logging out');

        const icon = button.querySelector('[data-logout-icon]');
        const spinner = button.querySelector('[data-logout-spinner]');
        const label = button.querySelector('[data-logout-label]');
        const status = button.querySelector('[data-logout-status]');

        if (icon) icon.hidden = true;
        if (spinner) spinner.hidden = false;
        if (label) label.textContent = 'Logging out…';
        if (status) status.textContent = 'Logging out.';

        // Defer the native disabled state until the submit event has completed,
        // so the first secure POST is never interrupted by UI state changes.
        deferLogoutButtonDisable(() => {
            if (form.dataset.logoutSubmitting === 'true') {
                button.disabled = true;
            }
        });
    });

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            document.querySelectorAll('[data-logout-form]').forEach(resetLogoutForm);
        }
    });

    const nextPasswordFieldId = () => {
        let candidate = '';

        do {
            passwordFieldCounter += 1;
            candidate = `password-field-${passwordFieldCounter}`;
        } while (document.getElementById(candidate));

        return candidate;
    };

    const passwordToggleMarkup = (isVisible) => {
        const icon = isVisible
            ? '<svg class="password-toggle__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3.6 12s3.1-5.2 8.4-5.2c1.3 0 2.5.3 3.6.8"></path><path d="M20.4 12s-3.1 5.2-8.4 5.2c-1.3 0-2.5-.3-3.6-.8"></path><path d="M14.1 10a2.8 2.8 0 0 1-4 4"></path><path d="M4.5 4.5 19.5 19.5" stroke-width="2"></path></svg>'
            : '<svg class="password-toggle__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3.6 12s3.1-5.2 8.4-5.2 8.4 5.2 8.4 5.2-3.1 5.2-8.4 5.2S3.6 12 3.6 12z"></path><circle cx="12" cy="12" r="2.7"></circle></svg>';
        const label = isVisible ? 'Hide password' : 'Show password';

        return `${icon}<span class="sr-only">${label}</span>`;
    };

    const updatePasswordToggle = (toggle, isVisible) => {
        const label = isVisible ? 'Hide password' : 'Show password';
        toggle.setAttribute('aria-pressed', String(isVisible));
        toggle.setAttribute('aria-label', label);
        toggle.title = label;
        toggle.innerHTML = passwordToggleMarkup(isVisible);
    };

    const hidePasswordField = (input) => {
        if (!(input instanceof HTMLInputElement) || input.dataset.passwordToggleReady !== 'true') {
            return;
        }

        input.type = 'password';

        const toggle = input.closest('.password-field')?.querySelector('[data-password-toggle]');
        if (toggle instanceof HTMLButtonElement) {
            updatePasswordToggle(toggle, false);
        }
    };

    const enhancePasswordField = (input) => {
        if (
            !(input instanceof HTMLInputElement)
            || input.type !== 'password'
            || input.dataset.passwordToggleReady === 'true'
        ) {
            return;
        }

        if (input.id === '' || document.getElementById(input.id) !== input) {
            input.id = nextPasswordFieldId();
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'password-field';
        input.before(wrapper);
        wrapper.append(input);

        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'password-toggle';
        toggle.dataset.passwordToggle = input.id;
        toggle.setAttribute('aria-controls', input.id);
        toggle.disabled = input.disabled;
        updatePasswordToggle(toggle, false);
        wrapper.append(toggle);

        input.dataset.passwordToggleReady = 'true';
    };

    const enhancePasswordFieldsWithin = (rootNode) => {
        if (rootNode instanceof HTMLInputElement && rootNode.type === 'password') {
            enhancePasswordField(rootNode);
        }

        if (rootNode instanceof Element || rootNode instanceof Document) {
            rootNode.querySelectorAll('input[type="password"]').forEach(enhancePasswordField);
        }
    };

    enhancePasswordFieldsWithin(document);

    document.addEventListener('click', (event) => {
        const toggle = event.target instanceof Element
            ? event.target.closest('[data-password-toggle]')
            : null;

        if (!(toggle instanceof HTMLButtonElement)) {
            return;
        }

        const input = document.getElementById(toggle.dataset.passwordToggle || '');
        if (!(input instanceof HTMLInputElement) || input.dataset.passwordToggleReady !== 'true') {
            return;
        }

        const shouldShow = input.type === 'password';
        const selectionStart = input.selectionStart;
        const selectionEnd = input.selectionEnd;
        const selectionDirection = input.selectionDirection;

        input.type = shouldShow ? 'text' : 'password';
        updatePasswordToggle(toggle, shouldShow);

        if (selectionStart !== null && selectionEnd !== null) {
            try {
                input.setSelectionRange(selectionStart, selectionEnd, selectionDirection || 'none');
            } catch (error) {
                // Some older browsers do not restore selection on password inputs.
            }
        }
    });

    document.addEventListener('reset', (event) => {
        if (!(event.target instanceof HTMLFormElement)) {
            return;
        }

        window.setTimeout(() => {
            event.target.querySelectorAll('[data-password-toggle-ready="true"]').forEach(hidePasswordField);
        }, 0);
    });

    const passwordFieldObserver = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.type === 'attributes') {
                if (
                    mutation.attributeName === 'disabled'
                    && mutation.target instanceof HTMLInputElement
                    && mutation.target.dataset.passwordToggleReady === 'true'
                ) {
                    const toggle = mutation.target.closest('.password-field')?.querySelector('[data-password-toggle]');
                    if (toggle instanceof HTMLButtonElement) {
                        toggle.disabled = mutation.target.disabled;
                    }

                    if (mutation.target.disabled) {
                        hidePasswordField(mutation.target);
                    }
                }

                enhancePasswordField(mutation.target);
                return;
            }

            mutation.addedNodes.forEach(enhancePasswordFieldsWithin);
        });
    });

    passwordFieldObserver.observe(document.body, {
        subtree: true,
        childList: true,
        attributes: true,
        attributeFilter: ['type', 'disabled'],
    });

    if (themeButton && themeSlot) {
        const contextClasses = (themeSlot.dataset.systemThemeClass || '')
            .split(/\s+/)
            .filter(Boolean);

        themeButton.classList.remove('system-theme-toggle--standalone');

        if (contextClasses.length > 0) {
            themeButton.classList.add(...contextClasses);
        }

        if (themeSlot.hasAttribute('data-mobile-drawer-item')) {
            themeButton.setAttribute('data-mobile-drawer-item', '');
        }

        themeSlot.replaceWith(themeButton);
    }

    if (themeButton && !themeSlot && document.querySelector('.auth-home-button')) {
        themeButton.classList.add('system-theme-toggle--beside-home');
    }

    themeButton?.classList.add('is-ready');

    const readSavedTheme = () => {
        try {
            const savedTheme = localStorage.getItem(themeStorageKey);
            return savedTheme === 'dark' || savedTheme === 'light' ? savedTheme : '';
        } catch (error) {
            return '';
        }
    };

    const applySystemTheme = (theme, persist = true) => {
        const nextTheme = theme === 'dark' ? 'dark' : 'light';
        const isDark = nextTheme === 'dark';
        root.dataset.theme = nextTheme;

        if (persist) {
            try {
                localStorage.setItem(themeStorageKey, nextTheme);
            } catch (error) {
                // Storage can be unavailable in restricted browsing contexts.
            }
        }

        if (themeButton) {
            const nextLabel = isDark ? 'Switch to light mode' : 'Switch to dark mode';
            themeButton.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            themeButton.setAttribute('aria-label', nextLabel);
            themeButton.setAttribute('title', nextLabel);
        }
    };

    const initialTheme = readSavedTheme()
        || root.dataset.theme
        || (window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');

    applySystemTheme(initialTheme, false);

    themeButton?.addEventListener('click', () => {
        applySystemTheme(root.dataset.theme === 'dark' ? 'light' : 'dark');
    });

    const colorSchemeQuery = window.matchMedia?.('(prefers-color-scheme: dark)');
    const syncUnsavedSystemTheme = (event) => {
        if (!readSavedTheme()) {
            applySystemTheme(event.matches ? 'dark' : 'light', false);
        }
    };

    if (colorSchemeQuery?.addEventListener) {
        colorSchemeQuery.addEventListener('change', syncUnsavedSystemTheme);
    } else if (colorSchemeQuery?.addListener) {
        colorSchemeQuery.addListener(syncUnsavedSystemTheme);
    }

    const isSafeGoogleMapsEmbed = (value) => {
        try {
            const url = new URL(value);
            const host = url.hostname.toLowerCase();

            return url.protocol === 'https:'
                && ['google.com', 'www.google.com', 'maps.google.com'].includes(host)
                && url.pathname.toLowerCase().includes('/maps')
                && !/[<>]/.test(value);
        } catch (error) {
            return false;
        }
    };

    document.querySelectorAll('[data-external-map]').forEach((container) => {
        const preview = container.querySelector('iframe[data-map-src]');
        const prompt = container.querySelector('[data-external-map-prompt]');
        const load = container.querySelector('[data-external-map-load]');
        const reject = container.querySelector('[data-external-map-reject]');
        const unload = container.querySelector('[data-external-map-unload]');
        const status = container.querySelector('[data-external-map-status]');
        if (!(preview instanceof HTMLIFrameElement) || !load || !unload || !prompt) return;

        load.addEventListener('click', () => {
            const url = preview.dataset.mapSrc || '';
            if (!isSafeGoogleMapsEmbed(url)) {
                if (status) status.textContent = 'Map preview is unavailable. Use the Google Maps link instead.';
                return;
            }

            preview.src = url;
            preview.hidden = false;
            prompt.hidden = true;
            unload.hidden = false;
            container.dataset.mapConsent = 'true';
        });

        reject?.addEventListener('click', () => {
            if (status) status.textContent = 'Map kept off. You can load it later if you choose.';
        });

        unload.addEventListener('click', () => {
            preview.removeAttribute('src');
            preview.hidden = true;
            prompt.hidden = false;
            unload.hidden = true;
            delete container.dataset.mapConsent;
            if (status) status.textContent = 'Map is off again. Any information already sent to Google cannot be recalled.';
        });
    });

    document.querySelectorAll('[data-shop-location-form]').forEach((form) => {
        const preview = form.closest('.content-grid')?.querySelector('[data-shop-map-preview]')
            || document.querySelector('[data-shop-map-preview]');
        const embedInput = form.querySelector('[data-shop-embed-url]');
        const latitudeInput = form.querySelector('[data-shop-latitude]');
        const longitudeInput = form.querySelector('[data-shop-longitude]');

        if (!preview) {
            return;
        }

        const updatePreview = () => {
            const embedUrl = (embedInput?.value || '').trim();

            if (embedUrl !== '' && isSafeGoogleMapsEmbed(embedUrl)) {
                preview.dataset.mapSrc = embedUrl;
                if (preview.closest('[data-external-map]')?.dataset.mapConsent === 'true') preview.src = embedUrl;
                return;
            }

            const latitude = (latitudeInput?.value || '').trim();
            const longitude = (longitudeInput?.value || '').trim();
            const latNumber = Number(latitude);
            const lngNumber = Number(longitude);

            if (
                latitude !== ''
                && longitude !== ''
                && Number.isFinite(latNumber)
                && Number.isFinite(lngNumber)
                && latNumber >= -90
                && latNumber <= 90
                && lngNumber >= -180
                && lngNumber <= 180
            ) {
                preview.dataset.mapSrc = `https://www.google.com/maps?q=${encodeURIComponent(`${latitude},${longitude}`)}&z=17&output=embed`;
                if (preview.closest('[data-external-map]')?.dataset.mapConsent === 'true') preview.src = preview.dataset.mapSrc;
            }
        };

        [embedInput, latitudeInput, longitudeInput].forEach((input) => {
            if (input) {
                input.addEventListener('input', updatePreview);
                input.addEventListener('change', updatePreview);
            }
        });
    });

    document.querySelectorAll('.portal-product-card__media img, .product-card__media img, .product-detail-dialog__media img').forEach((image) => {
        const showImage = () => {
            const media = image.closest('.portal-product-card__media, .product-card__media, .product-detail-dialog__media');

            if (!media || image.naturalWidth === 0) {
                return;
            }

            media.classList.remove('is-missing-image');
            image.hidden = false;
        };

        const showImageFallback = () => {
            const media = image.closest('.portal-product-card__media, .product-card__media, .product-detail-dialog__media');

            if (!media) {
                return;
            }

            media.classList.add('is-missing-image');
            image.hidden = true;
        };

        image.addEventListener('load', showImage);
        image.addEventListener('error', showImageFallback);

        if (image.complete && image.naturalWidth === 0) {
            showImageFallback();
        }
    });

    let sidebar = document.querySelector('[data-sidebar]');
    const toggle = document.querySelector('[data-toggle-sidebar]');

    if (toggle) {
        const mobileDrawerQuery = window.matchMedia('(max-width: 1024px)');
        const hasStaticSidebar = Boolean(sidebar);
        const createSharedDrawer = () => {
            if (sidebar) {
                return sidebar;
            }

            const drawer = document.createElement('aside');
            drawer.className = 'sidebar sidebar--shared-drawer';
            if (
                document.body.classList.contains('landing-portal-body')
                || document.body.classList.contains('catalog-page')
                || document.body.classList.contains('contact-page-body')
            ) {
                drawer.classList.add('sidebar--portal-drawer');
            }
            drawer.id = toggle.getAttribute('aria-controls') || 'app-sidebar';
            drawer.setAttribute('data-sidebar', '');
            drawer.innerHTML = [
                '<div class="sidebar__header">',
                '<div class="sidebar__logo"></div>',
                '<div class="sidebar__identity">',
                '<span class="sidebar__eyebrow">Menu</span>',
                "<h2>Fred's Batchoy</h2>",
                '</div>',
                '<button class="sidebar__close" type="button" data-close-sidebar aria-label="Close menu" title="Close menu">',
                '<span aria-hidden="true">&times;</span>',
                '</button>',
                '</div>',
            ].join('');

            const portalLogo = document.querySelector('.portal-brand__logo img, [data-drawer-logo]');
            const drawerLogo = drawer.querySelector('.sidebar__logo');

            if (portalLogo && drawerLogo) {
                const logoImage = portalLogo.cloneNode();
                logoImage.classList.remove('sr-only');
                logoImage.removeAttribute('data-drawer-logo');
                logoImage.removeAttribute('hidden');
                logoImage.alt = '';
                logoImage.setAttribute('aria-hidden', 'true');
                drawerLogo.replaceChildren(logoImage);
            }

            document.body.appendChild(drawer);
            sidebar = drawer;

            return drawer;
        };

        sidebar = createSharedDrawer();

        const backdrop = document.createElement('div');
        backdrop.className = 'sidebar-backdrop';
        backdrop.setAttribute('aria-hidden', 'true');
        sidebar.before(backdrop);

        const pageMain = document.querySelector('main');
        const ensureCloseButton = () => {
            const header = sidebar.querySelector('.sidebar__header');
            const buttons = Array.from(sidebar.querySelectorAll('[data-close-sidebar]'));
            let button = buttons.shift();

            buttons.forEach((duplicate) => duplicate.remove());

            if (button) {
                if (header && button.parentElement !== header) {
                    header.appendChild(button);
                }

                return button;
            }

            button = document.createElement('button');
            button.className = 'sidebar__close';
            button.type = 'button';
            button.setAttribute('data-close-sidebar', '');
            button.setAttribute('aria-label', 'Close menu');
            button.setAttribute('title', 'Close menu');
            button.innerHTML = '<span aria-hidden="true">&times;</span>';

            if (header) {
                header.appendChild(button);
            } else {
                sidebar.prepend(button);
            }

            return button;
        };
        const closeButton = ensureCloseButton();

        const ensureMenuLines = () => {
            let bars = toggle.querySelector('.menu-toggle__bars');

            if (!bars) {
                bars = document.createElement('span');
                bars.className = 'menu-toggle__bars';
                bars.setAttribute('aria-hidden', 'true');
                toggle.prepend(bars);
            }

            const lines = bars.querySelectorAll('.menu-toggle__bar');

            if (lines.length !== 3) {
                bars.replaceChildren();

                for (let index = 0; index < 3; index += 1) {
                    const line = document.createElement('span');
                    line.className = 'menu-toggle__bar';
                    line.setAttribute('data-menu-line', '');
                    bars.appendChild(line);
                }
            }
        };

        const ensureMobileDrawerTarget = () => {
            let target = sidebar.querySelector('[data-mobile-drawer-target]');

            if (!target) {
                target = document.createElement('div');
                target.className = 'mobile-drawer__extras';
                target.setAttribute('data-mobile-drawer-target', '');

                const header = sidebar.querySelector('.sidebar__header');

                if (header?.nextSibling) {
                    sidebar.insertBefore(target, header.nextSibling);
                } else {
                    sidebar.appendChild(target);
                }
            }

            return target;
        };

        const mobileDrawerCandidates = Array.from(document.querySelectorAll('[data-mobile-drawer-item]'))
            .filter((item) => item instanceof HTMLElement);
        // Track only top-level items. Nested markers (for example a theme toggle
        // inside a user group) move twice and create duplicated/overlapping drawer rows.
        const mobileDrawerItems = mobileDrawerCandidates
            .filter((item) => !mobileDrawerCandidates.some((parent) => parent !== item && parent.contains(item)))
            .map((item) => {
                const marker = document.createComment('mobile-drawer-item');
                item.after(marker);

                return { item, marker };
            });

        const setDesktopSidebarExpanded = (isExpanded) => {
            if (!hasStaticSidebar || mobileDrawerQuery.matches) {
                return;
            }

            const shouldExpand = Boolean(isExpanded);
            document.body.classList.toggle('sidebar-desktop-collapsed', !shouldExpand);
            sidebar.inert = !shouldExpand;
            sidebar.setAttribute('aria-hidden', shouldExpand ? 'false' : 'true');
            toggle.classList.toggle('is-open', shouldExpand);
            toggle.setAttribute('aria-expanded', shouldExpand ? 'true' : 'false');
            toggle.setAttribute('aria-label', shouldExpand ? 'Hide menu' : 'Show menu');
            toggle.setAttribute('title', shouldExpand ? 'Hide menu' : 'Show menu');
        };

        const setSidebarOpen = (isOpen) => {
            const shouldOpen = Boolean(isOpen);

            if (!shouldOpen && sidebar.contains(document.activeElement)) {
                toggle.focus();
            }

            sidebar.classList.toggle('is-open', shouldOpen);
            sidebar.inert = !shouldOpen;
            sidebar.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
            backdrop.classList.toggle('is-visible', shouldOpen);
            toggle.classList.toggle('is-open', shouldOpen);
            document.body.classList.toggle('sidebar-open', shouldOpen);

            if (pageMain) {
                pageMain.inert = shouldOpen;
            }

            toggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
            toggle.setAttribute('aria-label', shouldOpen ? 'Close menu' : 'Open menu');
            toggle.setAttribute('title', shouldOpen ? 'Close menu' : 'Open menu');

            if (shouldOpen) {
                closeButton?.focus();
            }
        };

        const syncMobileDrawer = () => {
            const shouldUseDrawer = mobileDrawerQuery.matches;
            const target = ensureMobileDrawerTarget();

            mobileDrawerItems.forEach(({ item, marker }) => {
                if (shouldUseDrawer) {
                    if (item.parentElement !== target) {
                        target.appendChild(item);
                    }

                    return;
                }

                if (marker.parentNode && item.nextSibling !== marker) {
                    marker.parentNode.insertBefore(item, marker);
                }
            });

            sidebar.classList.toggle('has-mobile-extras', shouldUseDrawer && mobileDrawerItems.length > 0);

            if (!mobileDrawerQuery.matches && hasStaticSidebar) {
                setSidebarOpen(false);
                setDesktopSidebarExpanded(true);
                return;
            }

            // A breakpoint change must never carry the desktop collapsed/open
            // state into the overlay drawer. Always initialise drawer mode closed.
            document.body.classList.remove('sidebar-desktop-collapsed');
            setSidebarOpen(false);
        };

        const sidebarScrollScope = sidebar.dataset.sidebarScope
            || (document.querySelector('.customer-topbar') ? 'customer' : 'public');
        const sidebarScrollStorageKey = `freds-sidebar-scroll-v1:${sidebarScrollScope}`;
        let sidebarScrollFrame = 0;

        const readSidebarScrollPosition = () => {
            try {
                const storedValue = window.sessionStorage.getItem(sidebarScrollStorageKey);
                if (storedValue === null || storedValue.trim() === '') {
                    return null;
                }

                const position = Number(storedValue);
                return Number.isFinite(position) && position >= 0 ? position : null;
            } catch (_) {
                return null;
            }
        };

        const saveSidebarScrollPosition = () => {
            try {
                window.sessionStorage.setItem(
                    sidebarScrollStorageKey,
                    String(Math.max(0, Math.round(sidebar.scrollTop)))
                );
            } catch (_) {
                // Storage can be unavailable in privacy-restricted browsing modes.
            }
        };

        const clampSidebarScrollPosition = (position) => Math.min(
            Math.max(0, position),
            Math.max(0, sidebar.scrollHeight - sidebar.clientHeight)
        );

        const keepActiveSidebarLinkVisible = () => {
            const activeLink = sidebar.querySelector('.sidebar__link.is-active, a.is-active');
            if (!(activeLink instanceof HTMLElement)) {
                return;
            }

            const sidebarBounds = sidebar.getBoundingClientRect();
            const linkBounds = activeLink.getBoundingClientRect();
            const edgePadding = 12;

            if (linkBounds.top < sidebarBounds.top + edgePadding) {
                sidebar.scrollTop -= sidebarBounds.top + edgePadding - linkBounds.top;
            } else if (linkBounds.bottom > sidebarBounds.bottom - edgePadding) {
                sidebar.scrollTop += linkBounds.bottom - (sidebarBounds.bottom - edgePadding);
            }
        };

        const restoreSidebarScrollPosition = () => {
            const savedPosition = readSidebarScrollPosition();

            if (savedPosition === null) {
                keepActiveSidebarLinkVisible();
                return;
            }

            const restore = () => {
                sidebar.scrollTop = clampSidebarScrollPosition(savedPosition);
            };

            restore();
            window.requestAnimationFrame(restore);
        };

        const queueSidebarScrollSave = () => {
            if (sidebarScrollFrame) {
                return;
            }

            sidebarScrollFrame = window.requestAnimationFrame(() => {
                sidebarScrollFrame = 0;
                saveSidebarScrollPosition();
            });
        };

        ensureMenuLines();
        syncMobileDrawer();
        restoreSidebarScrollPosition();

        sidebar.addEventListener('scroll', queueSidebarScrollSave, { passive: true });
        window.addEventListener('pagehide', saveSidebarScrollPosition);
        window.addEventListener('pageshow', restoreSidebarScrollPosition);

        if (mobileDrawerQuery.addEventListener) {
            mobileDrawerQuery.addEventListener('change', syncMobileDrawer);
        } else if (mobileDrawerQuery.addListener) {
            mobileDrawerQuery.addListener(syncMobileDrawer);
        }

        toggle.addEventListener('click', (event) => {
            event.stopPropagation();

            if (!mobileDrawerQuery.matches && hasStaticSidebar) {
                setDesktopSidebarExpanded(document.body.classList.contains('sidebar-desktop-collapsed'));
                return;
            }

            setSidebarOpen(!sidebar.classList.contains('is-open'));
        });

        closeButton?.addEventListener('click', () => {
            setSidebarOpen(false);
            toggle.focus();
        });

        backdrop.addEventListener('click', () => {
            setSidebarOpen(false);
            toggle.focus();
        });

        document.addEventListener('click', (event) => {
            if (!sidebar.classList.contains('is-open')) {
                return;
            }

            if (sidebar.contains(event.target) || toggle.contains(event.target)) {
                return;
            }

            setSidebarOpen(false);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
                setSidebarOpen(false);
                toggle.focus();
            }
        });

        sidebar.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => {
                saveSidebarScrollPosition();
                setSidebarOpen(false);
            });
        });
    }

    document.querySelectorAll('.data-table').forEach((table) => {
        const headers = Array.from(table.querySelectorAll('thead th')).map((header) => header.textContent.trim());

        table.querySelectorAll('tbody tr').forEach((row) => {
            Array.from(row.children).forEach((cell, index) => {
                if (!cell.hasAttribute('data-label') && headers[index]) {
                    cell.setAttribute('data-label', headers[index]);
                }
            });
        });
    });

    const managementActionMeta = (rawLabel) => {
        const normalized = String(rawLabel || '').replace(/\s+/g, ' ').trim().toLowerCase();
        const definitions = [
            { terms: ['deactivate', 'disable'], label: normalized.includes('deactivate') ? 'Deactivate' : 'Disable', tone: 'warning', icon: 'disable' },
            { terms: ['delete', 'remove'], label: normalized.includes('remove') ? 'Remove' : 'Delete', tone: 'danger', icon: 'delete' },
            { terms: ['reject'], label: 'Reject', tone: 'danger', icon: 'reject' },
            { terms: ['cancel'], label: 'Cancel', tone: 'danger', icon: 'reject' },
            { terms: ['edit'], label: 'Edit', tone: 'edit', icon: 'edit' },
            { terms: ['activate', 'restore', 'recover'], label: normalized.includes('activate') ? 'Activate' : (normalized.includes('restore') ? 'Restore' : 'Recover'), tone: 'success', icon: 'restore' },
            { terms: ['approve', 'accept'], label: normalized.includes('approve') ? 'Approve' : 'Accept', tone: 'success', icon: 'approve' },
            { terms: ['verify'], label: 'Verify', tone: 'success', icon: 'approve' },
            { terms: ['archive'], label: 'Archive', tone: 'warning', icon: 'archive' },
            { terms: ['assign'], label: 'Assign', tone: 'primary', icon: 'assign' },
            { terms: ['update'], label: 'Update', tone: 'primary', icon: 'update' },
            { terms: ['save'], label: 'Save', tone: 'primary', icon: 'update' },
            { terms: ['delivered', 'delivery'], label: normalized.includes('out for delivery') ? 'Out for Delivery' : 'Delivered', tone: 'primary', icon: 'delivery' },
            { terms: ['receipt'], label: 'Receipt', tone: 'neutral', icon: 'view' },
            { terms: ['view', 'review', 'open'], label: normalized.includes('review') ? 'Review' : 'View', tone: 'neutral', icon: 'view' },
            { terms: ['close'], label: 'Close', tone: 'neutral', icon: 'close' },
        ];
        const match = definitions.find((definition) => definition.terms.some((term) => normalized.includes(term)));

        return match || {
            label: String(rawLabel || 'Action').replace(/\s+/g, ' ').trim(),
            tone: 'primary',
            icon: 'update',
        };
    };

    const managementActionIcon = (icon) => {
        const icons = {
            edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 20h9"></path><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"></path></svg>',
            disable: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="m5.6 5.6 12.8 12.8"></path></svg>',
            delete: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18"></path><path d="M8 6V4h8v2"></path><path d="M19 6l-1 14H6L5 6"></path><path d="M10 11v5M14 11v5"></path></svg>',
            reject: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="m9 9 6 6m0-6-6 6"></path></svg>',
            approve: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="m8 12 2.6 2.6L16.5 9"></path></svg>',
            restore: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 8V4m0 0h4M5 4l3.2 3.2A7 7 0 1 1 5.6 15"></path></svg>',
            archive: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16v13H4z"></path><path d="M3 4h18v3H3zM9 11h6"></path></svg>',
            assign: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="9" cy="8" r="3"></circle><path d="M3.5 19a5.5 5.5 0 0 1 11 0M17 8v6m-3-3h6"></path></svg>',
            update: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 7v5h-5"></path><path d="M18.4 16a8 8 0 1 1 .7-7.2L20 12"></path></svg>',
            delivery: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h11v11H3zM14 10h4l3 3v4h-7z"></path><circle cx="7" cy="18" r="2"></circle><circle cx="18" cy="18" r="2"></circle></svg>',
            print: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 8V3.5h12V8"></path><path d="M6 18H4.5A2.5 2.5 0 0 1 2 15.5v-5A2.5 2.5 0 0 1 4.5 8h15a2.5 2.5 0 0 1 2.5 2.5v5a2.5 2.5 0 0 1-2.5 2.5H18"></path><path d="M6 14.5h12v6H6z"></path><path d="M9 17.5h6"></path><circle cx="18.5" cy="11" r=".7" fill="currentColor" stroke="none"></circle></svg>',
            'support-headset': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4.5 11V9a7.5 7.5 0 0 1 15 0v2"></path><rect x="2.5" y="10" width="4" height="7" rx="2" fill="currentColor" stroke="none"></rect><rect x="17.5" y="10" width="4" height="7" rx="2" fill="currentColor" stroke="none"></rect><path d="M19.5 17c0 2.6-2.1 4.2-5.7 4.2h-1"></path><rect x="9.5" y="20" width="3.5" height="2.4" rx="1.2" fill="currentColor" stroke="none"></rect></svg>',
            view: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 12s3.3-6 9-6 9 6 9 6-3.3 6-9 6-9-6-9-6Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg>',
            close: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>',
        };

        return icons[icon] || icons.update;
    };

    document.querySelectorAll('.data-table tbody td').forEach((cell) => {
        if (cell.matches('[data-status-action-controls]') || cell.querySelector('[data-status-action-controls]')) {
            return;
        }

        if (!/(^actions?$|\bassign\b|\bupdate\b|\bmanage\b|\bapproval\b|\bdecision\b)/i.test((cell.getAttribute('data-label') || '').trim())) {
            return;
        }

        const controls = Array.from(cell.querySelectorAll('a.btn, button.btn'));
        if (controls.length === 0) {
            return;
        }

        cell.classList.add('system-action-cell');
        let actionSet = cell.querySelector(':scope > .table-actions, :scope > .system-action-set');

        if (!actionSet) {
            actionSet = document.createElement('div');
            actionSet.className = 'system-action-set';
            const units = [];

            controls.forEach((control) => {
                const form = control.closest('form');
                const unit = form && cell.contains(form) ? form : control;
                if (!units.includes(unit)) {
                    units.push(unit);
                }
            });

            cell.appendChild(actionSet);
            units.forEach((unit) => actionSet.appendChild(unit));
        } else {
            actionSet.classList.add('system-action-set');
        }

        controls.forEach((control) => {
            const originalLabel = control.getAttribute('aria-label')
                || control.getAttribute('title')
                || control.textContent.trim()
                || 'Action';
            const meta = managementActionMeta(originalLabel);
            let item = control.closest('.user-action-item, .system-action-item');

            if (!item) {
                item = document.createElement('span');
                control.parentNode.insertBefore(item, control);
                item.appendChild(control);
            }

            item.classList.add('system-action-item', `system-action-item--${meta.tone}`);
            control.classList.add('system-action-button');
            control.setAttribute('aria-label', originalLabel);
            control.setAttribute('title', originalLabel);
            control.innerHTML = managementActionIcon(control.dataset.actionIcon || meta.icon);

            let label = Array.from(item.children).find((child) => child.classList?.contains('user-action-label') || child.classList?.contains('system-action-label'));
            if (!label) {
                label = document.createElement('span');
                item.appendChild(label);
            }
            label.classList.add('system-action-label');
            label.setAttribute('aria-hidden', 'true');
            label.textContent = control.dataset.actionLabel || meta.label;

            const form = item.closest('form');
            if (form && actionSet.contains(form)) {
                form.classList.add('system-action-form');
            }
        });

        actionSet.style.setProperty('--action-count', String(Math.max(1, Math.min(controls.length, 5))));
    });

    document.querySelectorAll('.table-actions, [data-management-actions]').forEach((actionSet) => {
        if (actionSet.matches('[data-status-action-controls]') || actionSet.closest('[data-status-action-controls]')) {
            return;
        }

        if (actionSet.classList.contains('system-action-set')) {
            return;
        }

        const controls = Array.from(actionSet.querySelectorAll('a.btn, button.btn'));
        if (controls.length === 0) {
            return;
        }

        actionSet.classList.add('system-action-set');
        controls.forEach((control) => {
            const originalLabel = control.getAttribute('aria-label')
                || control.getAttribute('title')
                || control.textContent.trim()
                || 'Action';
            const meta = managementActionMeta(originalLabel);
            let item = control.closest('.user-action-item, .system-action-item');

            if (!item) {
                item = document.createElement('span');
                control.parentNode.insertBefore(item, control);
                item.appendChild(control);
            }

            item.classList.add('system-action-item', `system-action-item--${meta.tone}`);
            control.classList.add('system-action-button');
            control.setAttribute('aria-label', originalLabel);
            control.setAttribute('title', originalLabel);
            control.innerHTML = managementActionIcon(control.dataset.actionIcon || meta.icon);

            let label = Array.from(item.children).find((child) => child.classList?.contains('user-action-label') || child.classList?.contains('system-action-label'));
            if (!label) {
                label = document.createElement('span');
                item.appendChild(label);
            }
            label.classList.add('system-action-label');
            label.setAttribute('aria-hidden', 'true');
            label.textContent = control.dataset.actionLabel || meta.label;

            const form = item.closest('form');
            if (form && actionSet.contains(form)) {
                form.classList.add('system-action-form');
            }
        });
        actionSet.style.setProperty('--action-count', String(Math.max(1, Math.min(controls.length, 5))));
    });

    document.querySelectorAll('[data-export-table]').forEach((button) => {
        button.addEventListener('click', () => {
            const tableName = button.getAttribute('data-export-table');
            const table = tableName
                ? document.querySelector(`[data-export-name="${tableName}"]`)
                : button.closest('.form-card')?.querySelector('table');

            if (!table) {
                return;
            }

            const csvCell = (cell) => {
                let value = (cell.getAttribute('data-export-value') ?? cell.innerText).replace(/\r?\n/g, ' ').trim();

                // Spreadsheet programs can execute cells beginning with these
                // characters as formulas. Prefix them so exports remain data.
                if (/^[\t\r ]*[=+\-@]/.test(value)) {
                    value = `'${value}`;
                }

                return `"${value.replace(/"/g, '""')}"`;
            };

            const rows = Array.from(table.querySelectorAll('tr')).map((row) =>
                Array.from(row.querySelectorAll('th:not([data-export-exclude]), td:not([data-export-exclude])'))
                    .map(csvCell)
                    .join(',')
            );

            const blob = new Blob([rows.join('\n')], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const objectUrl = URL.createObjectURL(blob);
            link.href = objectUrl;
            link.download = `${tableName || 'table-export'}.csv`;
            link.hidden = true;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
        });
    });

    const normalizeThermalWidth = (value) => value === '58mm' ? '58mm' : '80mm';
    document.querySelectorAll('[data-thermal-paper-select]').forEach((select) => {
        const receipt = select.closest('.section')?.querySelector('[data-thermal-receipt]')
            || document.querySelector('[data-thermal-receipt]');
        if (!receipt) {
            return;
        }

        const syncThermalControls = () => {
            const width = normalizeThermalWidth(select.value);
            receipt.dataset.paperWidth = width;
            receipt.classList.toggle('paper-58', width === '58mm');
            receipt.classList.toggle('paper-80', width === '80mm');
            receipt.querySelectorAll('[data-thermal-text-preview]').forEach((preview) => {
                if (!(preview instanceof HTMLElement)) return;
                const active = preview.dataset.thermalTextPreview === width;
                preview.hidden = !active;
                preview.setAttribute('aria-hidden', active ? 'false' : 'true');
            });
            const controls = select.closest('.thermal-receipt-controls');
            const localForm = controls?.querySelector('[data-thermal-local-form]');
            controls?.querySelectorAll('[data-thermal-browser-print-link]').forEach((link) => {
                if (!(link instanceof HTMLAnchorElement)) return;
                const target = new URL(link.href, window.location.href);
                target.searchParams.set('paper', width);
                link.href = target.toString();
                link.hidden = localForm instanceof HTMLFormElement && width === '58mm';
            });
            if (localForm instanceof HTMLFormElement) {
                localForm.hidden = width !== '58mm';
                const submit = localForm.querySelector('[data-thermal-local-submit]');
                if (submit instanceof HTMLButtonElement) submit.disabled = width !== '58mm';
            }
        };
        select.addEventListener('change', syncThermalControls);
        syncThermalControls();
    });

    const quantityInputBounds = (input) => {
        const parsedMinimum = Number.parseInt(input.getAttribute('min') || '1', 10);
        const parsedMaximum = Number.parseInt(input.getAttribute('max') || '9999', 10);
        const minimum = Number.isFinite(parsedMinimum) ? parsedMinimum : 1;
        const maximum = Number.isFinite(parsedMaximum) ? Math.max(minimum, parsedMaximum) : 9999;

        return {
            minimum,
            maximum,
        };
    };

    const normalizedQuantityValue = (input, usePersistedFallback = false) => {
        const { minimum, maximum } = quantityInputBounds(input);
        const rawValue = String(input.value || '').trim();
        const numericValue = rawValue === '' ? Number.NaN : Number(rawValue);
        let quantity = Number.isInteger(numericValue) ? numericValue : null;

        if (quantity === null && usePersistedFallback) {
            const persistedValue = Number(input.dataset.persistedValue);
            quantity = Number.isInteger(persistedValue) ? persistedValue : minimum;
        }

        if (quantity === null) return null;

        return Math.max(minimum, Math.min(maximum, quantity));
    };

    const synchronizeQuantityStepper = (input) => {
        const stepper = input.closest('[data-qty-stepper]');
        if (!stepper) return;

        const { minimum, maximum } = quantityInputBounds(input);
        const quantity = normalizedQuantityValue(input);
        const decreaseButton = stepper.querySelector('[data-qty-step="-1"]');
        const increaseButton = stepper.querySelector('[data-qty-step="1"]');
        const hasValidQuantity = quantity !== null;

        if (decreaseButton) decreaseButton.disabled = !hasValidQuantity || quantity <= minimum;
        if (increaseButton) increaseButton.disabled = !hasValidQuantity || quantity >= maximum;
    };

    document.querySelectorAll('[data-cart-form]').forEach((cartForm) => {
        const previewUrl = cartForm.dataset.cartPreviewUrl || '';
        const cartScope = cartForm.parentElement || document;
        const summaryPanel = cartScope.querySelector('.cart-summary-panel');
        const cartRows = new Map([...cartForm.querySelectorAll('[data-cart-item][data-product-id]')]
            .map((row) => [String(row.dataset.productId || ''), row]));
        let previewSequence = 0;
        let cartUpdateQueue = Promise.resolve();
        const status = document.createElement('p');
        status.className = 'alert alert-danger';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.hidden = true;
        cartForm.insertAdjacentElement('afterend', status);

        const setPreviewMessage = (message = '', type = 'danger') => {
            status.textContent = message;
            status.className = `alert alert-${type}`;
            status.hidden = message === '';
        };

        const setText = (selector, value) => {
            const target = cartScope.querySelector(selector);
            if (target && value !== undefined && value !== null) target.textContent = String(value);
        };

        const formatRate = (value) => {
            const rate = Number.parseFloat(value);
            return Number.isFinite(rate)
                ? rate.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1')
                : '0';
        };

        const rememberPersistedCartValues = (payload) => {
            Object.entries(payload.items || {}).forEach(([productId, item]) => {
                const input = cartRows.get(String(productId))?.querySelector('input[name^="quantities["]');
                if (input) input.dataset.persistedValue = String(Math.max(1, Number.parseInt(item.quantity || '1', 10) || 1));
            });
        };

        const restorePersistedCartValues = () => {
            cartForm.querySelectorAll('input[name^="quantities["]').forEach((input) => {
                const quantity = normalizedQuantityValue(input, true);
                input.value = String(quantity ?? 1);
                synchronizeQuantityStepper(input);
            });
        };

        const applyCartPreview = (payload) => {
            rememberPersistedCartValues(payload);
            Object.entries(payload.items || {}).forEach(([productId, item]) => {
                const row = cartRows.get(String(productId));
                if (!row) return;
                const input = row.querySelector('input[name^="quantities["]');
                const maxQuantity = Math.max(0, Number.parseInt(item.max_quantity || '0', 10) || 0);

                if (input) {
                    input.max = String(maxQuantity);
                    input.value = String(Math.max(1, Number.parseInt(item.quantity || '1', 10) || 1));
                    synchronizeQuantityStepper(input);
                }

                const availability = row.querySelector('[data-cart-stock-label]');
                if (availability) availability.textContent = `Available: ${maxQuantity}`;
                const subtotal = row.querySelector('[data-cart-item-subtotal]');
                if (subtotal && item.subtotal_label) subtotal.textContent = item.subtotal_label;
            });

            const pricing = payload.pricing || {};
            setText('[data-cart-subtotal]', pricing.subtotal_label);
            setText('[data-cart-discount-rate]', formatRate(pricing.discount_rate));
            setText('[data-cart-discount-amount]', pricing.discount_amount_label ? `-${pricing.discount_amount_label}` : null);
            setText('[data-cart-promo-discount]', pricing.promo_discount_amount_label ? `-${pricing.promo_discount_amount_label}` : null);
            setText('[data-cart-vatable-sales]', pricing.vatable_sales_label);
            setText('[data-cart-tax-rate]', formatRate(pricing.tax_rate));
            setText('[data-cart-vat-amount]', pricing.vat_amount_label);
            setText('[data-cart-grand-total]', pricing.total_label);
        };

        const requestCartPreview = async () => {
            if (!previewUrl) return;
            const sequence = ++previewSequence;
            const formData = new FormData(cartForm);
            setPreviewMessage('');
            cartForm.setAttribute('aria-busy', 'true');
            summaryPanel?.setAttribute('aria-busy', 'true');

            cartUpdateQueue = cartUpdateQueue.catch(() => {}).then(async () => {
                try {
                    const response = await fetch(previewUrl, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        cache: 'no-store',
                    });
                    let payload = null;
                    try {
                        payload = await response.json();
                    } catch (error) {
                        // Login redirects and hosting error pages can return HTML.
                    }

                    if (response.status === 401 || (response.redirected && !payload)) {
                        throw new Error('Your session has expired. Please sign in again.');
                    }
                    if (!response.ok || !payload?.success) {
                        throw new Error(payload?.message || 'Unable to save the cart quantity.');
                    }
                    rememberPersistedCartValues(payload);
                    if (sequence !== previewSequence) return;
                    if (payload.cart_empty || (payload.removed_product_ids || []).length > 0) {
                        window.location.reload();
                        return;
                    }
                    applyCartPreview(payload);
                    setPreviewMessage((payload.messages || []).join(' '), 'warning');
                } catch (error) {
                    if (sequence === previewSequence) {
                        restorePersistedCartValues();
                        setPreviewMessage(error.message || 'Unable to save the cart quantity. Please try again.', 'danger');
                    }
                } finally {
                    if (sequence === previewSequence) {
                        cartForm.removeAttribute('aria-busy');
                        summaryPanel?.removeAttribute('aria-busy');
                    }
                }
            });
        };

        cartForm.addEventListener('input', (event) => {
            if (!event.target.matches('input[name^="quantities["]')) return;
            const quantity = normalizedQuantityValue(event.target);
            synchronizeQuantityStepper(event.target);
            if (quantity === null) return;
            event.target.value = String(quantity);
            previewSequence += 1;
            cartForm.removeAttribute('aria-busy');
            summaryPanel?.removeAttribute('aria-busy');
            requestCartPreview();
        });
    });

    // Prevent accidental double-clicks on normal POST forms. AJAX forms and
    // notification actions manage their own in-flight state.
    const resetStatusActionForm = (form) => {
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-status-action-form]')) return;

        delete form.dataset.submitting;
        form.removeAttribute('aria-busy');
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => {
            button.disabled = button.dataset.submitWasDisabled === 'true';
            delete button.dataset.submitWasDisabled;
            button.removeAttribute('aria-disabled');
            button.setAttribute('aria-busy', 'false');

            const label = button.querySelector?.('[data-status-action-label]');
            if (label) {
                label.textContent = label.dataset.readyLabel || label.textContent;
                button.setAttribute('aria-label', label.dataset.readyLabel || label.textContent);
            }
        });
    };

    const deferSubmitLock = window.queueMicrotask || ((callback) => Promise.resolve().then(callback));
    document.querySelectorAll('form[method="post"], form:not([method])').forEach((form) => {
        if (form.matches('[data-logout-form], [data-support-send-form], [data-support-create-form], [data-support-update-form], [data-support-note-form], [data-notification-bulk-form]')) {
            return;
        }

        form.addEventListener('submit', (event) => {
            if (form.dataset.submitting === 'true') {
                event.preventDefault();
                return;
            }

            if (event.defaultPrevented) {
                return;
            }

            form.dataset.submitting = 'true';
            form.setAttribute('aria-busy', 'true');
            const submitter = event.submitter;
            deferSubmitLock(() => {
                if (event.defaultPrevented) {
                    if (form.matches('[data-status-action-form]')) {
                        resetStatusActionForm(form);
                    } else {
                        delete form.dataset.submitting;
                        form.removeAttribute('aria-busy');
                    }
                    return;
                }

                form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => {
                    button.dataset.submitWasDisabled = String(button.disabled);
                    // A named submitter contributes its name/value to the POST.
                    // Keep it enabled for serialization while CSS blocks repeats.
                    if (button !== submitter || !button.name) {
                        button.disabled = true;
                    } else {
                        button.setAttribute('aria-disabled', 'true');
                    }
                    button.setAttribute('aria-busy', 'true');

                    if (button === submitter && form.matches('[data-status-action-form]')) {
                        const label = button.querySelector?.('[data-status-action-label]');
                        if (label) {
                            label.textContent = label.dataset.loadingLabel || 'Updating...';
                            button.setAttribute('aria-label', label.textContent);
                        }
                    }
                });
            });
        });
    });

    window.addEventListener('pageshow', () => {
        document.querySelectorAll('[data-status-action-form]').forEach(resetStatusActionForm);
    });

    const closeGuestCartPanels = (exceptCart = null) => {
        document.querySelectorAll('[data-guest-cart]').forEach((cart) => {
            if (exceptCart && cart === exceptCart) {
                return;
            }

            const panel = cart.querySelector('[data-guest-cart-panel]');
            const toggleButton = cart.querySelector('[data-guest-cart-toggle]');
            const scrim = cart.querySelector('.guest-cart__scrim');

            panel?.setAttribute('hidden', '');
            scrim?.setAttribute('hidden', '');
            toggleButton?.setAttribute('aria-expanded', 'false');
        });
    };

    document.querySelectorAll('[data-guest-cart]').forEach((cart) => {
        const toggleButton = cart.querySelector('[data-guest-cart-toggle]');
        const panel = cart.querySelector('[data-guest-cart-panel]');
        const scrim = cart.querySelector('.guest-cart__scrim');

        if (!toggleButton || !panel) {
            return;
        }

        const setGuestCartOpen = (open) => {
            if (open) {
                closeGuestCartPanels(cart);
                panel.removeAttribute('hidden');
                scrim?.removeAttribute('hidden');
                toggleButton.setAttribute('aria-expanded', 'true');
                return;
            }

            panel.setAttribute('hidden', '');
            scrim?.setAttribute('hidden', '');
            toggleButton.setAttribute('aria-expanded', 'false');
        };

        toggleButton.addEventListener('click', (event) => {
            event.stopPropagation();
            setGuestCartOpen(panel.hasAttribute('hidden'));
        });

        cart.querySelectorAll('[data-guest-cart-close]').forEach((button) => {
            button.addEventListener('click', () => setGuestCartOpen(false));
        });

        panel.addEventListener('click', (event) => {
            event.stopPropagation();
        });
    });

    document.addEventListener('click', () => closeGuestCartPanels());
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeGuestCartPanels();
        }
    });

    document.querySelectorAll('[data-qty-stepper]').forEach((stepper) => {
        const input = stepper.querySelector('input[type="number"]');

        if (!input) {
            return;
        }

        input.dataset.persistedValue ||= input.value;
        synchronizeQuantityStepper(input);

        stepper.querySelectorAll('[data-qty-step]').forEach((button) => {
            button.addEventListener('click', () => {
                if (button.disabled) return;
                const step = parseInt(button.getAttribute('data-qty-step') || '0', 10);
                const { minimum, maximum } = quantityInputBounds(input);
                const current = normalizedQuantityValue(input, true) ?? minimum;
                const next = Math.max(minimum, Math.min(maximum, current + step));

                input.value = String(next);
                synchronizeQuantityStepper(input);
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });

        input.addEventListener('input', () => synchronizeQuantityStepper(input));
        input.addEventListener('change', () => {
            const previousValue = input.value;
            const quantity = normalizedQuantityValue(input, true);
            input.value = String(quantity ?? 1);
            synchronizeQuantityStepper(input);

            if (input.value !== previousValue) {
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
    });

    const productDetailDialog = document.querySelector('[data-product-detail-dialog]');

    if (productDetailDialog) {
        const detailImage = productDetailDialog.querySelector('[data-product-detail-image]');
        const detailCategory = productDetailDialog.querySelector('[data-product-detail-category]');
        const detailName = productDetailDialog.querySelector('[data-product-detail-name]');
        const detailDescription = productDetailDialog.querySelector('[data-product-detail-description]');
        const detailPrice = productDetailDialog.querySelector('[data-product-detail-price]');
        const detailStock = productDetailDialog.querySelector('[data-product-detail-stock]');

        const closeProductDetail = () => {
            if (typeof productDetailDialog.close === 'function') {
                productDetailDialog.close();
                return;
            }

            productDetailDialog.removeAttribute('open');
        };

        document.querySelectorAll('[data-product-detail]').forEach((trigger) => {
            trigger.addEventListener('click', (event) => {
                event.preventDefault();

                const product = trigger.dataset;

                if (detailImage) {
                    const media = detailImage.closest('.product-detail-dialog__media');
                    const imageUrl = (product.productImage || '').trim();
                    media?.classList.remove('is-missing-image');
                    detailImage.hidden = false;
                    detailImage.alt = product.productName || 'Product image';

                    if (imageUrl) {
                        detailImage.src = imageUrl;
                    } else {
                        detailImage.removeAttribute('src');
                        detailImage.hidden = true;
                        media?.classList.add('is-missing-image');
                    }
                }

                if (detailCategory) {
                    detailCategory.textContent = product.productCategory || 'Product';
                }

                if (detailName) {
                    detailName.textContent = product.productName || 'Product Details';
                }

                if (detailDescription) {
                    detailDescription.textContent = product.productDescription || 'No product description available.';
                }

                if (detailPrice) {
                    detailPrice.textContent = product.productPrice || '';
                }

                if (detailStock) {
                    detailStock.textContent = product.productStock || '';
                }

                if (typeof productDetailDialog.showModal === 'function') {
                    productDetailDialog.showModal();
                } else {
                    productDetailDialog.setAttribute('open', '');
                }
            });
        });

        productDetailDialog.querySelectorAll('[data-product-detail-close]').forEach((button) => {
            button.addEventListener('click', closeProductDetail);
        });

        productDetailDialog.addEventListener('click', (event) => {
            if (event.target === productDetailDialog) {
                closeProductDetail();
            }
        });
    }

    const verificationDetailsDialog = document.querySelector('[data-verification-details-dialog]');

    if (verificationDetailsDialog instanceof HTMLElement) {
        let verificationDetailsTrigger = null;
        const field = (selector) => verificationDetailsDialog.querySelector(selector);
        const setText = (selector, value, fallback = '-') => {
            const target = field(selector);
            if (target) {
                target.textContent = value || fallback;
            }
        };
        const setBadge = (selector, label, tone) => {
            const target = field(selector);
            if (!target) {
                return;
            }

            target.className = `badge badge-${tone}`;
            target.textContent = label || 'Unknown';
        };
        const closeVerificationDetails = () => {
            if (typeof verificationDetailsDialog.close === 'function') {
                verificationDetailsDialog.close();
            } else {
                verificationDetailsDialog.removeAttribute('open');
            }
        };

        document.querySelectorAll('[data-verification-details-open]').forEach((trigger) => {
            trigger.addEventListener('click', () => {
                verificationDetailsTrigger = trigger;
                const details = trigger.dataset;
                const customerId = details.customerId || '';
                const validIdUrl = details.validIdUrl || '';
                const canDecide = details.canDecide === 'true';
                const verificationStatus = details.verificationStatus || 'Pending';
                const statusTone = verificationStatus.toLowerCase() === 'approved'
                    ? 'success'
                    : (verificationStatus.toLowerCase() === 'rejected' ? 'danger' : 'warning');

                setText('[data-verification-detail-name]', details.customerName, 'Customer Details');
                setText('[data-verification-detail-full-name]', details.customerName);
                setText('[data-verification-detail-email]', details.customerEmail);
                setText('[data-verification-detail-phone]', details.customerPhone, 'Not provided');
                setText('[data-verification-detail-address]', details.customerAddress, 'Not provided');
                setText('[data-verification-detail-account-status]', details.accountStatus);
                setText('[data-verification-detail-registered]', details.registeredAt);
                setText('[data-verification-detail-updated]', details.updatedAt);
                setText('[data-verification-detail-reviewer]', details.reviewerName, 'Not reviewed');
                setText('[data-verification-detail-reviewer-role]', details.reviewerRole, 'Not assigned');
                setText('[data-verification-detail-reviewed-at]', details.reviewedAt, 'Not reviewed');
                setText('[data-verification-detail-notes]', details.verificationNotes, 'No notes recorded.');
                setBadge('[data-verification-detail-status]', verificationStatus, statusTone);

                const idImage = field('[data-verification-detail-id-image]');
                const idLink = field('[data-verification-detail-id-link]');
                const idMissing = field('[data-verification-detail-id-missing]');

                if (idLink instanceof HTMLAnchorElement) {
                    idLink.hidden = validIdUrl === '';
                    if (validIdUrl !== '') {
                        idLink.href = validIdUrl;
                    } else {
                        idLink.removeAttribute('href');
                    }
                }

                if (idImage instanceof HTMLImageElement) {
                    const showImage = validIdUrl !== '' && details.validIdIsImage === 'true';
                    idImage.hidden = !showImage;
                    if (showImage) {
                        idImage.src = validIdUrl;
                        idImage.alt = `Uploaded valid ID for ${details.customerName || 'customer'}`;
                    } else {
                        idImage.removeAttribute('src');
                        idImage.alt = '';
                    }
                }

                if (idMissing) {
                    idMissing.hidden = validIdUrl !== '';
                }

                const approveForm = field('[data-verification-detail-approve]');
                const rejectForm = field('[data-verification-detail-reject]');
                const noAction = field('[data-verification-detail-no-action]');

                [approveForm, rejectForm].forEach((form) => {
                    if (!(form instanceof HTMLFormElement)) {
                        return;
                    }

                    form.hidden = !canDecide;
                    const customerInput = form.querySelector('input[name="customer_id"]');
                    if (customerInput instanceof HTMLInputElement) {
                        customerInput.value = customerId;
                    }
                });

                const rejectionNotes = rejectForm?.querySelector('input[name="verification_notes"]');
                if (rejectionNotes instanceof HTMLInputElement) {
                    rejectionNotes.value = '';
                }

                if (noAction) {
                    noAction.hidden = canDecide;
                }

                if (typeof verificationDetailsDialog.showModal === 'function') {
                    verificationDetailsDialog.showModal();
                } else {
                    verificationDetailsDialog.setAttribute('open', 'open');
                }
            });
        });

        verificationDetailsDialog.querySelectorAll('[data-verification-details-close]').forEach((button) => {
            button.addEventListener('click', closeVerificationDetails);
        });

        verificationDetailsDialog.addEventListener('click', (event) => {
            if (event.target === verificationDetailsDialog) {
                closeVerificationDetails();
            }
        });

        verificationDetailsDialog.addEventListener('close', () => {
            if (verificationDetailsTrigger instanceof HTMLElement) {
                verificationDetailsTrigger.focus();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && verificationDetailsDialog.hasAttribute('open')) {
                closeVerificationDetails();
            }
        });
    }

    const notificationCenters = document.querySelectorAll('[data-notification-center]');

    if (notificationCenters.length > 0 && !window.FredsNotificationsShared) {
        const unreadLabel = (count) => (count > 99 ? '99+' : String(count));

        const setNotificationCount = (count, providedLabel = '') => {
            const unreadCount = Math.max(0, parseInt(count || '0', 10));
            const label = providedLabel || unreadLabel(unreadCount);

            document.querySelectorAll('[data-notification-count], [data-notification-nav-count]').forEach((badge) => {
                badge.textContent = label;
                badge.hidden = unreadCount === 0;
                badge.setAttribute('aria-label', unreadCount > 0 ? `${label} unread notifications` : 'No unread notifications');
                badge.closest('.sidebar__link, .customer-topbar__nav a')?.classList.toggle('has-unread-notifications', unreadCount > 0);
            });

            notificationCenters.forEach((center) => {
                const button = center.querySelector('[data-notification-toggle]');
                const summary = center.querySelector('[data-notification-summary]');

                if (button) {
                    button.classList.toggle('has-unread', unreadCount > 0);
                    const labelText = unreadCount > 0
                        ? `Notifications: ${label} unread update${unreadCount === 1 ? '' : 's'}. View alerts, messages, order status, payment, delivery, and system announcements.`
                        : 'Notifications: no new updates. View alerts, messages, order status, payment, delivery, and system announcements.';
                    button.setAttribute('aria-label', labelText);
                    button.setAttribute('title', labelText);
                }

                if (summary) {
                    summary.textContent = unreadCount > 0 ? `${label} unread` : 'No unread updates';
                }
            });
        };

        const setNotificationMenuCounts = (menuCounts = {}) => {
            Object.entries(menuCounts || {}).forEach(([key, value]) => {
                const count = Math.max(0, parseInt(value?.count || '0', 10));
                const label = value?.label || unreadLabel(count);

                document.querySelectorAll(`[data-notification-menu-badge="${key}"]`).forEach((badge) => {
                    badge.textContent = label;
                    badge.hidden = count === 0;
                    badge.setAttribute('aria-label', count > 0 ? `${label} unread updates` : 'No unread updates');
                    badge.closest('.sidebar__link')?.classList.toggle('has-unread-notifications', count > 0);
                });
            });
        };

        window.FredsNotifications = Object.assign(window.FredsNotifications || {}, {
            setMenuCounts: setNotificationMenuCounts,
            setTotalCount: setNotificationCount,
        });

        const setStatusBadge = (badge, status) => {
            if (!badge) {
                return;
            }

            const normalizedStatus = status === 'unread' ? 'unread' : 'read';
            badge.textContent = normalizedStatus === 'unread' ? 'Unread' : 'Read';
            badge.classList.remove('badge-warning', 'badge-success', 'badge-muted');
            badge.classList.add(normalizedStatus === 'unread' ? 'badge-warning' : 'badge-success');
        };

        const closeNotificationPanels = (exceptCenter = null) => {
            notificationCenters.forEach((center) => {
                if (center === exceptCenter) {
                    return;
                }

                const panel = center.querySelector('[data-notification-panel]');
                const toggleButton = center.querySelector('[data-notification-toggle]');

                if (panel) {
                    panel.hidden = true;
                }

                if (toggleButton) {
                    toggleButton.setAttribute('aria-expanded', 'false');
                }
            });
        };

        notificationCenters.forEach((center) => {
            const toggleButton = center.querySelector('[data-notification-toggle]');
            const panel = center.querySelector('[data-notification-panel]');
            const detail = center.querySelector('[data-notification-detail]');
            const detailTitle = detail?.querySelector('[data-notification-detail-title]');
            const detailMessage = detail?.querySelector('[data-notification-detail-message]');
            const detailDate = detail?.querySelector('[data-notification-detail-date]');
            const detailStatus = detail?.querySelector('[data-notification-detail-status]');
            const detailType = detail?.querySelector('[data-notification-detail-type]');
            const detailSource = detail?.querySelector('[data-notification-detail-source]');

            if (!toggleButton || !panel) {
                return;
            }

            toggleButton.addEventListener('click', (event) => {
                event.stopPropagation();
                const willOpen = panel.hidden;
                closeNotificationPanels(center);
                panel.hidden = !willOpen;
                toggleButton.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });

            panel.addEventListener('click', (event) => {
                event.stopPropagation();
            });

            center.querySelectorAll('[data-notification-close]').forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    panel.hidden = true;
                    toggleButton.setAttribute('aria-expanded', 'false');
                    toggleButton.focus();
                });
            });

            center.querySelectorAll('[data-notification-item]').forEach((item) => {
                item.addEventListener('click', () => {
                    const title = item.dataset.notificationTitle || 'Notification';
                    const message = item.dataset.notificationMessage || 'No message available.';
                    const date = item.dataset.notificationDate || '-';
                    const type = item.dataset.notificationType || 'Information';
                    const source = item.dataset.notificationSource || 'System';
                    const currentStatus = item.dataset.notificationStatus === 'unread' ? 'unread' : 'read';

                    if (detailTitle) {
                        detailTitle.textContent = title;
                    }

                    if (detailMessage) {
                        detailMessage.textContent = message;
                    }

                    if (detailDate) {
                        detailDate.textContent = date;
                    }

                    if (detailType) {
                        detailType.textContent = type;
                    }

                    if (detailSource) {
                        detailSource.textContent = source;
                    }

                    setStatusBadge(detailStatus, currentStatus);

                    if (currentStatus !== 'unread') {
                        return;
                    }

                    const formData = new FormData();
                    formData.append('csrf_token', center.dataset.notificationCsrf || '');
                    formData.append('notification_id', item.dataset.notificationId || '');

                    fetch(center.dataset.notificationReadUrl || '', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    })
                        .then((response) => response.ok ? response.json() : Promise.reject(response))
                        .then((payload) => {
                            if (!payload.success) {
                                return;
                            }

                            item.dataset.notificationStatus = 'read';
                            item.classList.remove('is-unread');
                            setStatusBadge(item.querySelector('[data-notification-item-status]'), 'read');
                            setStatusBadge(detailStatus, 'read');
                            setNotificationCount(payload.unread_count, payload.unread_label);
                            setNotificationMenuCounts(payload.menu_counts);
                        })
                        .catch(() => {
                            setStatusBadge(detailStatus, 'unread');
                        });
                });
            });
        });

        document.querySelectorAll('[data-notification-page]').forEach((page) => {
            const dialog = page.querySelector('[data-notification-page-dialog]');
            const dialogTitle = page.querySelector('[data-notification-dialog-title]');
            const dialogMessage = page.querySelector('[data-notification-dialog-message]');
            const dialogType = page.querySelector('[data-notification-dialog-type]');
            const dialogTypeText = page.querySelector('[data-notification-dialog-type-text]');
            const dialogDate = page.querySelector('[data-notification-dialog-date]');
            const dialogSource = page.querySelector('[data-notification-dialog-source]');
            const dialogStatus = page.querySelector('[data-notification-dialog-status]');
            const dialogRelated = page.querySelector('[data-notification-dialog-related]');
            const unreadMetric = page.querySelector('[data-notification-page-unread]');

            const openDialog = () => {
                if (!dialog) {
                    return;
                }

                if (typeof dialog.showModal === 'function') {
                    dialog.showModal();
                } else {
                    dialog.setAttribute('open', 'open');
                }
            };

            const closeDialog = () => {
                if (!dialog) {
                    return;
                }

                if (typeof dialog.close === 'function') {
                    dialog.close();
                } else {
                    dialog.removeAttribute('open');
                }
            };

            page.querySelectorAll('[data-notification-page-close]').forEach((button) => {
                button.addEventListener('click', closeDialog);
            });

            if (dialog) {
                dialog.addEventListener('click', (event) => {
                    if (event.target === dialog) {
                        closeDialog();
                    }
                });
            }

            page.querySelectorAll('[data-notification-page-item]').forEach((item) => {
                item.addEventListener('click', () => {
                    const title = item.dataset.notificationTitle || 'Notification';
                    const message = item.dataset.notificationMessage || 'No message available.';
                    const type = item.dataset.notificationType || 'Information';
                    const date = item.dataset.notificationDate || '-';
                    const source = item.dataset.notificationSource || 'System';
                    const relatedUrl = item.dataset.notificationRelatedUrl || '';
                    const currentStatus = item.dataset.notificationStatus === 'unread' ? 'unread' : 'read';

                    if (dialogTitle) {
                        dialogTitle.textContent = title;
                    }

                    if (dialogMessage) {
                        dialogMessage.textContent = message;
                    }

                    if (dialogType) {
                        dialogType.textContent = type;
                    }

                    if (dialogTypeText) {
                        dialogTypeText.textContent = type;
                    }

                    if (dialogDate) {
                        dialogDate.textContent = date;
                    }

                    if (dialogSource) {
                        dialogSource.textContent = source;
                    }

                    setStatusBadge(dialogStatus, currentStatus);

                    if (dialogRelated) {
                        if (relatedUrl !== '') {
                            dialogRelated.href = relatedUrl;
                            dialogRelated.hidden = false;
                        } else {
                            dialogRelated.hidden = true;
                            dialogRelated.removeAttribute('href');
                        }
                    }

                    openDialog();

                    if (currentStatus !== 'unread') {
                        return;
                    }

                    const formData = new FormData();
                    formData.append('csrf_token', page.dataset.notificationCsrf || '');
                    formData.append('notification_id', item.dataset.notificationId || '');

                    fetch(page.dataset.notificationReadUrl || '', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    })
                        .then((response) => response.ok ? response.json() : Promise.reject(response))
                        .then((payload) => {
                            if (!payload.success) {
                                return;
                            }

                            item.dataset.notificationStatus = 'read';
                            item.classList.remove('is-unread');
                            item.classList.add('is-read');
                            item.querySelector('[data-notification-unread-indicator]')?.remove();
                            setStatusBadge(item.querySelector('[data-notification-item-status]'), 'read');
                            setStatusBadge(dialogStatus, 'read');
                            setNotificationCount(payload.unread_count, payload.unread_label);
                            setNotificationMenuCounts(payload.menu_counts);

                            if (unreadMetric) {
                                unreadMetric.textContent = String(Math.max(0, parseInt(unreadMetric.textContent || '0', 10) - 1));
                            }
                        })
                        .catch(() => {
                            setStatusBadge(dialogStatus, 'unread');
                        });
                });
            });
        });

        const refreshNotificationCount = () => {
            const countUrl = notificationCenters[0]?.dataset.notificationCountUrl || '';

            if (!countUrl) {
                return;
            }

            fetch(countUrl, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
                .then((response) => response.ok ? response.json() : Promise.reject(response))
                .then((payload) => {
                    if (payload.success) {
                        setNotificationCount(payload.unread_count, payload.unread_label);
                        setNotificationMenuCounts(payload.menu_counts);
                    }
                })
                .catch(() => {
                    // Notification count refresh is progressive enhancement only.
                });
        };

        window.setInterval(refreshNotificationCount, 30000);
        document.addEventListener('click', () => closeNotificationPanels());
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeNotificationPanels();
            }
        });
    }

    document.querySelectorAll('[data-support-chat]').forEach((chat) => {
        const messagesBox = chat.querySelector('[data-support-messages]');
        const sendForm = chat.querySelector('[data-support-send-form]');
        const createForm = chat.querySelector('[data-support-create-form]');
        const updateForms = chat.querySelectorAll('[data-support-update-form], [data-support-note-form]');
        const pollUrl = chat.dataset.pollUrl || '';
        const sendUrl = chat.dataset.sendUrl || '';
        const createUrl = chat.dataset.createUrl || '';
        const autoUrl = chat.dataset.autoUrl || '';
        const supportEndpointBase = autoUrl || pollUrl;
        let fallbackMarkReadUrl = '';
        try {
            fallbackMarkReadUrl = supportEndpointBase
                ? new URL('mark_read.php', new URL(supportEndpointBase, window.location.href)).href
                : '';
        } catch (error) {
            fallbackMarkReadUrl = '';
        }
        const markReadUrl = chat.dataset.markReadUrl || fallbackMarkReadUrl;
        const csrf = chat.dataset.csrf || '';
        let conversationId = parseInt(chat.dataset.conversationId || '0', 10);
        let isPolling = false;
        let hasMarkedConversationRead = false;
        let lastMessageId = 0;
        const viewerId = parseInt(chat.dataset.viewerId || '0', 10);
        const viewerRole = chat.dataset.viewerRole || '';
        const isCustomerView = viewerRole
            ? viewerRole === 'customer'
            : !chat.classList.contains('support-layout--console');
        const supportFormStates = new WeakMap();
        const supportStatuses = new WeakMap();

        const setSupportStatus = (message, isError = false, context = chat) => {
            let status = supportStatuses.get(context)
                || context?.querySelector?.('[data-support-status], [data-support-runtime-status]')
                || null;

            if (!status) {
                status = document.createElement('p');
                status.className = 'support-form-status';
                status.dataset.supportRuntimeStatus = '';
                status.setAttribute('role', 'status');
                status.setAttribute('aria-live', 'polite');

                if (context instanceof HTMLFormElement) {
                    context.insertAdjacentElement('afterend', status);
                } else {
                    context?.appendChild?.(status);
                }
            }

            supportStatuses.set(context, status);
            status.textContent = message;
            status.classList.toggle('is-error', isError);
            status.hidden = message === '';
            status.setAttribute('aria-live', isError ? 'assertive' : 'polite');
        };

        chat.querySelectorAll('[data-support-status]').forEach((status) => {
            status.setAttribute('role', 'status');
            status.setAttribute('aria-live', 'polite');
        });

        const setSupportFormPending = (form, pending) => {
            const controls = [...form.querySelectorAll('button[type="submit"], input[type="submit"]')];

            if (pending) {
                if (form.dataset.submitting === 'true') {
                    return false;
                }

                supportFormStates.set(form, controls.map((control) => [control, control.disabled]));
                form.dataset.submitting = 'true';
                controls.forEach((control) => {
                    control.disabled = true;
                    control.setAttribute('aria-busy', 'true');
                });
                return true;
            }

            (supportFormStates.get(form) || []).forEach(([control, wasDisabled]) => {
                control.disabled = wasDisabled;
                control.removeAttribute('aria-busy');
            });
            supportFormStates.delete(form);
            delete form.dataset.submitting;
            return true;
        };

        const parseSupportResponse = async (response, fallbackMessage) => {
            let payload = null;

            try {
                payload = await response.json();
            } catch (error) {
                // A login redirect normally returns HTML instead of JSON.
            }

            if (response.status === 401 || (response.redirected && !payload)) {
                throw new Error('Your session has expired. Please sign in again.');
            }

            if (!response.ok || !payload?.success) {
                throw new Error(payload?.message || fallbackMessage);
            }

            return payload;
        };

        const postSupportAction = async (url, fallbackMessage) => {
            if (!url || !conversationId) return null;
            const formData = new FormData();
            formData.set('csrf_token', csrf);
            formData.set('conversation_id', String(conversationId));
            const response = await fetch(url, {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            return parseSupportResponse(response, fallbackMessage);
        };

        const updateNotificationBadges = (count, label, menuCounts = {}) => {
            window.FredsNotifications?.syncCounts?.(count, label, menuCounts);
        };

        const updateNotificationMenuBadges = (menuCounts = {}) => {
            if (window.FredsNotifications?.setMenuCounts) {
                window.FredsNotifications.setMenuCounts(menuCounts);
            }
        };

        const triggerAutoReply = () => postSupportAction(autoUrl, 'Unable to check automated replies.')
            .then((payload) => {
                if (payload?.message) appendMessage(payload.message);
                return payload;
            });

        const markSupportMessagesRead = () => postSupportAction(markReadUrl, 'Unable to mark support messages as read.')
            .then((payload) => {
                if (payload) hasMarkedConversationRead = true;
                if (payload && Object.prototype.hasOwnProperty.call(payload, 'unread_count')) {
                    updateNotificationBadges(payload.unread_count, payload.unread_label, payload.menu_counts || {});
                }
                return payload;
            });

        const appendMessage = (message) => {
            if (!messagesBox || !message || !message.id || messagesBox.querySelector(`[data-message-id="${message.id}"]`)) {
                return;
            }

            const item = document.createElement('article');
            item.className = 'support-message';
            item.dataset.messageId = String(message.id);

            const senderId = parseInt(message.sender_id || '0', 10);
            const hasExactSender = viewerId > 0 && senderId > 0;
            const isOwnMessage = hasExactSender
                ? senderId === viewerId
                : isCustomerView
                    ? message.sender_type === 'customer'
                    : message.sender_type !== 'customer' && !message.is_automated && message.sender_type !== 'automated';

            if (isOwnMessage) {
                item.classList.add('is-own');
            }

            if (message.is_automated) {
                item.classList.add('is-automated');
            }

            const meta = document.createElement('div');
            meta.className = 'support-message__meta';

            const label = document.createElement('strong');
            label.textContent = message.sender_label || 'Support';

            const time = document.createElement('span');
            time.textContent = message.created_label || message.created_at || '';

            const body = document.createElement('p');
            body.textContent = message.message || '';

            meta.append(label, time);
            item.append(meta, body);
            messagesBox.appendChild(item);
            lastMessageId = Math.max(lastMessageId, parseInt(message.id, 10));
            messagesBox.scrollTop = messagesBox.scrollHeight;
        };

        if (messagesBox) {
            messagesBox.querySelectorAll('[data-message-id]').forEach((item) => {
                lastMessageId = Math.max(lastMessageId, parseInt(item.dataset.messageId || '0', 10));
            });
            messagesBox.scrollTop = messagesBox.scrollHeight;
        }

        const pollMessages = () => {
            if (!pollUrl || !conversationId || isPolling) {
                return;
            }

            isPolling = true;
            const url = new URL(pollUrl, window.location.href);
            url.searchParams.set('conversation_id', String(conversationId));
            url.searchParams.set('after_id', String(lastMessageId));

            triggerAutoReply()
                .catch(() => null)
                .then(() => fetch(url, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    cache: 'no-store',
                }))
                .then((response) => parseSupportResponse(response, 'Unable to refresh messages.'))
                .then((payload) => {
                    if (!payload.success) {
                        return;
                    }

                    const receivedMessages = payload.messages || [];
                    receivedMessages.forEach(appendMessage);

                    if (Object.prototype.hasOwnProperty.call(payload, 'notification_count')) {
                        updateNotificationBadges(payload.notification_count, payload.notification_label, payload.menu_counts || {});
                    } else if (Object.prototype.hasOwnProperty.call(payload, 'menu_counts')) {
                        updateNotificationMenuBadges(payload.menu_counts);
                    }

                    return !hasMarkedConversationRead || receivedMessages.length > 0
                        ? markSupportMessagesRead()
                        : null;
                })
                .catch(() => {
                    // Polling is intentionally quiet; the next request can recover.
                })
                .finally(() => {
                    isPolling = false;
                });
        };

        if (sendForm) {
            sendForm.addEventListener('submit', (event) => {
                event.preventDefault();

                if (!sendUrl || !conversationId) {
                    setSupportStatus('Unable to send a message for this conversation.', true, sendForm);
                    return;
                }

                if (!setSupportFormPending(sendForm, true)) {
                    return;
                }

                const formData = new FormData(sendForm);
                formData.set('csrf_token', csrf || formData.get('csrf_token') || '');
                formData.set('conversation_id', String(conversationId));
                setSupportStatus('Sending message...', false, sendForm);

                fetch(sendUrl, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                })
                    .then((response) => parseSupportResponse(response, 'Unable to send message.'))
                    .then((payload) => {
                        appendMessage(payload.message);
                        sendForm.reset();
                        setSupportStatus('', false, sendForm);
                    })
                    .catch((error) => {
                        setSupportStatus(error.message || 'Unable to send message.', true, sendForm);
                    })
                    .finally(() => {
                        setSupportFormPending(sendForm, false);
                    });
            });
        }

        if (createForm) {
            createForm.addEventListener('submit', (event) => {
                event.preventDefault();

                if (!createUrl) {
                    setSupportStatus('Unable to create a conversation right now.', true, createForm);
                    return;
                }

                if (!setSupportFormPending(createForm, true)) {
                    return;
                }

                const formData = new FormData(createForm);
                formData.set('csrf_token', csrf || formData.get('csrf_token') || '');
                setSupportStatus('Creating conversation...', false, createForm);

                fetch(createUrl, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                })
                    .then((response) => parseSupportResponse(response, 'Unable to create conversation.'))
                    .then((payload) => {
                        window.location.href = payload.redirect_url || window.location.href;
                    })
                    .catch((error) => {
                        setSupportStatus(error.message || 'Unable to create conversation.', true, createForm);
                    })
                    .finally(() => {
                        setSupportFormPending(createForm, false);
                    });
            });
        }

        updateForms.forEach((form) => {
            form.addEventListener('submit', (event) => {
                event.preventDefault();

                if (!setSupportFormPending(form, true)) {
                    return;
                }

                const formData = new FormData(form);
                formData.set('csrf_token', csrf || formData.get('csrf_token') || '');
                setSupportStatus('Saving changes...', false, form);

                fetch(form.action, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                })
                    .then((response) => parseSupportResponse(response, 'Unable to update conversation.'))
                    .then((payload) => {
                        window.location.reload();
                    })
                    .catch((error) => {
                        setSupportStatus(error.message || 'Unable to update conversation.', true, form);
                    })
                    .finally(() => {
                        setSupportFormPending(form, false);
                    });
            });
        });

        if (conversationId) {
            pollMessages();
            window.setInterval(() => {
                if (document.hidden) {
                    return;
                }

                pollMessages();
            }, 10000);
            window.setInterval(() => {
                if (!document.hidden) {
                    return;
                }

                pollMessages();
            }, 45000);
        }
    });
});
