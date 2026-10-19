/* Shared notification state for every authenticated role and breakpoint. */
(() => {
    'use strict';

    window.FredsNotificationsShared = true;

    const ready = (callback) => document.readyState === 'loading'
        ? document.addEventListener('DOMContentLoaded', callback, { once: true })
        : callback();

    ready(() => {
        const centers = [...document.querySelectorAll('[data-notification-center]')];
        const pages = [...document.querySelectorAll('[data-notification-page]')];
        const root = centers[0] || pages[0];

        if (!root) {
            return;
        }

        const countUrl = root.dataset.notificationCountUrl || '';
        const readUrl = root.dataset.notificationReadUrl || '';
        const bulkUrl = root.dataset.notificationBulkUrl || '';
        const csrf = root.dataset.notificationCsrf || '';
        const scopeUserId = String(root.dataset.notificationScope || '');
        let pollId = 0;
        let refreshInFlight = false;
        const bulkFormStates = new WeakMap();
        const readRequests = new WeakMap();

        const unreadLabel = (count) => count > 99 ? '99+' : String(Math.max(0, count));
        const number = (value) => Math.max(0, Number.parseInt(value || '0', 10) || 0);

        const updateCounts = (count, label = '') => {
            const unread = number(count);
            const text = label || unreadLabel(unread);

            document.querySelectorAll('[data-notification-count], [data-notification-nav-count]').forEach((badge) => {
                badge.textContent = text;
                badge.hidden = unread === 0;
                badge.setAttribute('aria-label', unread ? `${text} unread notifications` : 'No unread notifications');
                badge.closest('.sidebar__link, .customer-topbar__nav a')?.classList.toggle('has-unread-notifications', unread > 0);
            });

            centers.forEach((center) => {
                const button = center.querySelector('[data-notification-toggle]');
                const summary = center.querySelector('[data-notification-summary]');
                if (button) {
                    button.classList.toggle('has-unread', unread > 0);
                    const message = unread
                        ? `Notifications: ${text} unread update${unread === 1 ? '' : 's'}.`
                        : 'Notifications: no new updates.';
                    button.setAttribute('aria-label', message);
                    button.setAttribute('title', message);
                }
                if (summary) summary.textContent = unread ? `${text} unread` : 'No unread updates';
            });
        };

        const updateMenuCounts = (menuCounts = {}) => {
            Object.entries(menuCounts).forEach(([key, value]) => {
                const count = number(value?.count);
                const label = value?.label || unreadLabel(count);
                document.querySelectorAll(`[data-notification-menu-badge="${key}"]`).forEach((badge) => {
                    badge.textContent = label;
                    badge.hidden = count === 0;
                    badge.closest('.sidebar__link')?.classList.toggle('has-unread-notifications', count > 0);
                });
            });
        };

        const isCurrentScope = (payload = {}) => {
            if (!scopeUserId) return false;
            return String(payload.scope_user_id ?? '') === scopeUserId;
        };

        const applyPayload = (payload = {}) => {
            if (!isCurrentScope(payload)) return;
            if (Object.prototype.hasOwnProperty.call(payload, 'unread_count')) updateCounts(payload.unread_count, payload.unread_label);
            if (payload.menu_counts) updateMenuCounts(payload.menu_counts);
        };

        const setItemRead = (id) => {
            document.querySelectorAll(`[data-notification-id="${id}"]`).forEach((item) => {
                item.dataset.notificationStatus = 'read';
                item.classList.remove('is-unread');
                item.classList.add('is-read');
                item.querySelector('[data-notification-unread-indicator]')?.remove();
                const badge = item.querySelector('[data-notification-item-status]');
                if (badge) {
                    badge.textContent = 'Read';
                    badge.classList.remove('badge-warning', 'badge-danger', 'badge-muted');
                    badge.classList.add('badge-success');
                }
            });
        };

        const pageMetricSelectors = {
            total: '[data-notification-page-total]',
            unread: '[data-notification-page-unread]',
            today: '[data-notification-page-today]',
            read: '[data-notification-page-read]',
        };

        const metricsFromPayload = (payload = {}) => payload.notification_metrics || payload.metrics || null;

        const updatePageMetrics = (metrics = null, deltas = {}, targetPage = null) => {
            const targets = targetPage ? [targetPage] : pages;
            targets.forEach((page) => {
                Object.entries(pageMetricSelectors).forEach(([key, selector]) => {
                    const metric = page.querySelector(selector);
                    if (!metric) return;

                    if (metrics && Object.prototype.hasOwnProperty.call(metrics, key)) {
                        metric.textContent = String(number(metrics[key]));
                        return;
                    }

                    if (Object.prototype.hasOwnProperty.call(deltas, key)) {
                        metric.textContent = String(Math.max(0, number(metric.textContent) + Number(deltas[key] || 0)));
                    }
                });
            });
        };

        const textElement = (tag, text, className = '') => {
            const element = document.createElement(tag);
            if (className) element.className = className;
            element.textContent = text;
            return element;
        };

        const setStatusBadge = (badge, status) => {
            if (!badge) return;
            const unread = status === 'unread';
            badge.textContent = unread ? 'Unread' : 'Read';
            badge.classList.remove('badge-warning', 'badge-success', 'badge-danger', 'badge-muted');
            badge.classList.add(unread ? 'badge-warning' : 'badge-success');
        };

        const notificationContainer = (context) => context?.closest?.('[data-notification-page], [data-notification-center]') || root;

        const setRequestStatus = (context, message, isError = false) => {
            const container = notificationContainer(context);
            if (!container) return;
            let status = container.querySelector('[data-notification-request-status]');

            if (!status && message === '') return;

            if (!status) {
                status = textElement('p', '', 'notification-request-status');
                status.dataset.notificationRequestStatus = '';
                status.setAttribute('role', 'status');
                const host = container.matches('[data-notification-page]')
                    ? container.querySelector('.notification-page__panel') || container
                    : container.querySelector('[data-notification-detail]') || container.querySelector('[data-notification-panel]') || container;
                host.prepend(status);
            }

            status.textContent = message;
            status.hidden = message === '';
            status.classList.toggle('is-error', isError);
            status.setAttribute('aria-live', isError ? 'assertive' : 'polite');
        };

        const closePageDialog = (dialog) => {
            if (!dialog) return;
            if (typeof dialog.close === 'function' && dialog.open) {
                dialog.close();
            } else {
                dialog.removeAttribute('open');
            }
        };

        const setDetailStatus = (item, status) => {
            const center = item.closest('[data-notification-center]');
            const page = item.closest('[data-notification-page]');
            setStatusBadge(center?.querySelector('[data-notification-detail-status]'), status);
            setStatusBadge(page?.querySelector('[data-notification-dialog-status]'), status);
        };

        const showNotificationDetail = (item) => {
            const title = item.dataset.notificationTitle || 'Notification';
            const message = item.dataset.notificationMessage || 'No message available.';
            const type = item.dataset.notificationType || 'Information';
            const date = item.dataset.notificationDate || '-';
            const source = item.dataset.notificationSource || 'System';
            const relatedUrl = item.dataset.notificationRelatedUrl || '';
            const status = item.dataset.notificationStatus === 'unread' ? 'unread' : 'read';
            const center = item.closest('[data-notification-center]');

            if (center) {
                const detail = center.querySelector('[data-notification-detail]');
                if (detail) {
                    detail.setAttribute('aria-live', 'polite');
                    const fields = {
                        '[data-notification-detail-title]': title,
                        '[data-notification-detail-message]': message,
                        '[data-notification-detail-type]': type,
                        '[data-notification-detail-date]': date,
                        '[data-notification-detail-source]': source,
                    };
                    Object.entries(fields).forEach(([selector, value]) => {
                        const field = detail.querySelector(selector);
                        if (field) field.textContent = value;
                    });

                    const related = detail.querySelector('[data-notification-detail-related]');
                    if (related) {
                        if (relatedUrl) {
                            related.href = relatedUrl;
                            related.dataset.notificationId = item.dataset.notificationId || '';
                            related.hidden = false;
                        } else {
                            related.hidden = true;
                            related.removeAttribute('href');
                            delete related.dataset.notificationId;
                        }
                    }
                }
            }

            const page = item.closest('[data-notification-page]');
            if (page) {
                const dialog = page.querySelector('[data-notification-page-dialog]');
                const fields = {
                    '[data-notification-dialog-title]': title,
                    '[data-notification-dialog-message]': message,
                    '[data-notification-dialog-type]': type,
                    '[data-notification-dialog-type-text]': type,
                    '[data-notification-dialog-date]': date,
                    '[data-notification-dialog-source]': source,
                };
                Object.entries(fields).forEach(([selector, value]) => {
                    const field = page.querySelector(selector);
                    if (field) field.textContent = value;
                });

                const related = page.querySelector('[data-notification-dialog-related]');
                if (related) {
                    if (relatedUrl) {
                        related.href = relatedUrl;
                        related.dataset.notificationId = item.dataset.notificationId || '';
                        related.hidden = false;
                    } else {
                        related.hidden = true;
                        related.removeAttribute('href');
                        delete related.dataset.notificationId;
                    }
                }

                if (dialog && !dialog.open) {
                    try {
                        if (typeof dialog.showModal === 'function') dialog.showModal();
                        else dialog.setAttribute('open', 'open');
                    } catch (error) {
                        dialog.setAttribute('open', 'open');
                    }
                }
            }

            setDetailStatus(item, status);
        };

        const setBulkPending = (form, pending) => {
            const controls = [...form.querySelectorAll('button[type="submit"], input[type="submit"]')];
            if (pending) {
                if (form.dataset.notificationPending === 'true') return false;
                bulkFormStates.set(form, controls.map((control) => [control, control.disabled]));
                form.dataset.notificationPending = 'true';
                controls.forEach((control) => {
                    control.disabled = true;
                    control.setAttribute('aria-busy', 'true');
                });
                return true;
            }

            (bulkFormStates.get(form) || []).forEach(([control, wasDisabled]) => {
                control.disabled = wasDisabled;
                control.removeAttribute('aria-busy');
            });
            bulkFormStates.delete(form);
            delete form.dataset.notificationPending;
            return true;
        };

        const syncPageNotifications = (notifications = [], metrics = null) => {
            if (!Array.isArray(notifications)) return;
            const received = new Map(notifications.map((item) => [String(item.id), item]));

            received.forEach((note, id) => {
                if (note.status === 'read') setItemRead(id);
            });

            pages.forEach((page) => {
                const list = page.querySelector('.notification-history-list');
                if (!list) return;
                if (received.size) list.querySelector('.notification-history-empty')?.remove();
                const existing = new Set([...list.querySelectorAll('[data-notification-page-item]')].map((item) => item.dataset.notificationId));
                const additions = [...received.entries()].filter(([id]) => !existing.has(id)).reverse();
                additions.forEach(([, note]) => {
                    const item = document.createElement('button');
                    const unread = note.status === 'unread';
                    item.type = 'button';
                    item.className = `notification-history-item notification-history-item--${note.type_key || 'info'} ${unread ? 'is-unread' : 'is-read'}`;
                    item.dataset.notificationPageItem = '';
                    item.dataset.notificationId = String(note.id);
                    item.dataset.notificationStatus = unread ? 'unread' : 'read';
                    item.dataset.notificationTitle = note.title || 'Notification';
                    item.dataset.notificationMessage = note.message || '';
                    item.dataset.notificationType = note.type || 'Information';
                    item.dataset.notificationDate = note.created_at || '';
                    item.dataset.notificationSource = note.source || 'System';
                    item.dataset.notificationRelatedUrl = note.link || '';
                    item.append(textElement('span', note.type_key === 'success' ? '✓' : note.type_key === 'warning' || note.type_key === 'danger' ? '!' : 'i', 'notification-history-item__icon'));
                    const body = document.createElement('span');
                    body.className = 'notification-history-item__body';
                    const title = document.createElement('span');
                    title.className = 'notification-history-item__title';
                    title.append(textElement('strong', note.title || 'Notification'));
                    if (unread) {
                        const unreadIndicator = textElement('em', 'Unread');
                        unreadIndicator.dataset.notificationUnreadIndicator = '';
                        title.append(unreadIndicator);
                    }
                    body.append(title, textElement('span', `${note.type || 'Information'} · ${note.source || 'System'} · ${note.created_at || ''}`, 'notification-history-item__meta'), textElement('small', note.message || ''));
                    const statusBadge = textElement('span', unread ? 'Unread' : 'Read', `badge ${unread ? 'badge-warning' : 'badge-success'}`);
                    statusBadge.dataset.notificationItemStatus = '';
                    item.append(body, statusBadge);
                    list.prepend(item);
                });
                if (!list.querySelector('[data-notification-page-item], .notification-history-empty')) {
                    const empty = document.createElement('div');
                    empty.className = 'empty-state notification-history-empty';
                    empty.append(textElement('h2', 'No notifications found'), textElement('p', 'Try another category or check back later.'));
                    list.append(empty);
                }
            });
            updatePageMetrics(metrics);
        };

        const syncCenterNotifications = (notifications = []) => {
            if (!Array.isArray(notifications)) return;

            centers.forEach((center) => {
                const list = center.querySelector('[data-notification-list]');
                if (!list) return;
                if (list.querySelector('[data-notification-pending="true"]')) return;
                list.replaceChildren();

                notifications.slice(0, 10).forEach((note) => {
                    const unread = note.status === 'unread';
                    const item = document.createElement('button');
                    item.type = 'button';
                    item.className = `notification-panel__item ${unread ? 'is-unread' : 'is-read'}`;
                    item.dataset.notificationItem = '';
                    item.dataset.notificationId = String(note.id);
                    item.dataset.notificationStatus = unread ? 'unread' : 'read';
                    item.dataset.notificationTitle = note.title || 'Notification';
                    item.dataset.notificationMessage = note.message || '';
                    item.dataset.notificationDate = note.created_at || '';
                    item.dataset.notificationType = note.type || 'Information';
                    item.dataset.notificationSource = note.source || 'System';
                    item.dataset.notificationRelatedUrl = note.link || '';

                    const body = document.createElement('span');
                    body.append(textElement('strong', note.title || 'Notification'), textElement('small', note.created_at || ''));
                    const status = textElement('span', unread ? 'Unread' : 'Read', `badge ${unread ? 'badge-warning' : 'badge-success'}`);
                    status.dataset.notificationItemStatus = '';
                    item.append(body, status);
                    list.append(item);
                });

                if (!notifications.length) {
                    list.append(textElement('div', 'No notifications yet.', 'notification-panel__empty'));
                }
            });
        };

        const readJsonResponse = async (response, fallbackMessage = 'Notification request failed.') => {
            let payload = null;

            try {
                payload = await response.json();
            } catch (error) {
                // Login redirects and hosting error pages can return HTML.
            }

            if (response.status === 401 || (response.redirected && !payload)) {
                throw new Error('Your session has expired. Please sign in again.');
            }

            if (!response.ok || !payload || payload.success === false) {
                throw new Error(payload?.message || fallbackMessage);
            }

            return payload;
        };

        const post = async (url, data) => {
            if (!url) throw new Error('Notification action is unavailable. Please refresh the page.');
            const formData = new FormData();
            formData.append('csrf_token', csrf);
            Object.entries(data).forEach(([key, value]) => formData.append(key, value));
            const response = await fetch(url, {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            return readJsonResponse(response);
        };

        const markRead = (item) => {
            if (item.dataset.notificationStatus !== 'unread') return null;
            const pendingRequest = readRequests.get(item);
            if (pendingRequest) return pendingRequest;

            item.dataset.notificationPending = 'true';
            const request = (async () => {
                try {
                    const page = item.closest('[data-notification-page]');
                    const type = page?.dataset.notificationTypeFilter || '';
                    const payload = await post(readUrl, {
                        notification_id: item.dataset.notificationId || '',
                        type,
                    });
                    setItemRead(item.dataset.notificationId || '');
                    if (page) updatePageMetrics(metricsFromPayload(payload), { unread: -1, read: 1 }, page);
                    applyPayload(payload);
                    channel?.postMessage({ type: 'changed', scopeUserId });
                    return payload;
                } finally {
                    delete item.dataset.notificationPending;
                    readRequests.delete(item);
                }
            })();
            readRequests.set(item, request);
            return request;
        };

        const closePanels = (except = null) => centers.forEach((center) => {
            if (center === except) return;
            const panel = center.querySelector('[data-notification-panel]');
            const toggle = center.querySelector('[data-notification-toggle]');
            if (panel) panel.hidden = true;
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
        });

        document.addEventListener('click', async (event) => {
            const toggle = event.target.closest('[data-notification-toggle]');
            if (toggle) {
                event.preventDefault();
                event.stopPropagation();
                const center = toggle.closest('[data-notification-center]');
                const panel = center?.querySelector('[data-notification-panel]');
                if (!panel) return;
                const open = panel.hidden;
                closePanels(center);
                panel.hidden = !open;
                toggle.setAttribute('aria-expanded', String(open));
                if (open) refresh();
                return;
            }

            const close = event.target.closest('[data-notification-close]');
            if (close) {
                event.preventDefault();
                const center = close.closest('[data-notification-center]');
                closePanels();
                center?.querySelector('[data-notification-toggle]')?.focus();
                return;
            }

            const pageClose = event.target.closest('[data-notification-page-close]');
            if (pageClose) {
                event.preventDefault();
                closePageDialog(pageClose.closest('[data-notification-page-dialog]'));
                return;
            }

            const pageDialog = event.target.closest('[data-notification-page-dialog]');
            if (pageDialog && event.target === pageDialog) {
                closePageDialog(pageDialog);
                return;
            }

            const item = event.target.closest('[data-notification-item], [data-notification-page-item]');
            if (item) {
                event.preventDefault();
                const relatedUrl = item.dataset.notificationRelatedUrl || '';
                if (!relatedUrl) showNotificationDetail(item);
                setRequestStatus(item, '');

                if (item.dataset.notificationStatus !== 'unread') {
                    if (relatedUrl) window.location.assign(relatedUrl);
                    return;
                }

                try {
                    const payload = await markRead(item);
                    if (!payload) return;
                    if (payload?.link && !item.dataset.notificationRelatedUrl) {
                        item.dataset.notificationRelatedUrl = payload.link;
                    }
                    const destination = relatedUrl || item.dataset.notificationRelatedUrl || '';
                    if (destination) {
                        window.location.assign(destination);
                        return;
                    }
                    setDetailStatus(item, 'read');
                    showNotificationDetail(item);
                } catch (error) {
                    showNotificationDetail(item);
                    setDetailStatus(item, 'unread');
                    setRequestStatus(item, error.message || 'Unable to mark this notification as read.', true);
                }
                return;
            }

            const related = event.target.closest('[data-notification-detail-related], [data-notification-dialog-related]');
            if (related && related.href) {
                const notificationId = related.dataset.notificationId || '';
                const item = notificationId ? document.querySelector(`[data-notification-id="${CSS.escape(notificationId)}"]`) : null;
                if (item?.dataset.notificationStatus === 'unread') {
                    event.preventDefault();
                    try {
                        await markRead(item);
                        window.location.assign(related.href);
                    } catch (error) {
                        setRequestStatus(related, error.message || 'Unable to open this notification.', true);
                    }
                }
                return;
            }

            const bulkForm = event.target.closest('[data-notification-bulk-form]');
            if (bulkForm && event.type === 'click') return;

            if (!event.target.closest('[data-notification-center]')) closePanels();
        });

        document.addEventListener('submit', async (event) => {
            const form = event.target.closest('[data-notification-bulk-form]');
            if (!form) {
                return;
            }
            if (!bulkUrl) return;
            event.preventDefault();
            const action = form.querySelector('[name="action"]')?.value || '';
            const page = form.closest('[data-notification-page]');
            const type = form.querySelector('[name="type"]')?.value || page?.dataset.notificationTypeFilter || '';
            if (!setBulkPending(form, true)) return;
            setRequestStatus(form, 'Updating notifications...');
            try {
                const payload = await post(bulkUrl, { action, type });
                if (action === 'mark_all_read') {
                    const target = type && form.closest('[data-notification-page]')
                        ? form.closest('[data-notification-page]')
                        : document;
                    target.querySelectorAll('[data-notification-status="unread"]').forEach((item) => setItemRead(item.dataset.notificationId || ''));
                } else if (action === 'delete_read') {
                    pages.forEach((page) => {
                        page.querySelectorAll('[data-notification-page-item][data-notification-status="read"]').forEach((item) => item.remove());
                        const list = page.querySelector('.notification-history-list');
                        if (list && !list.querySelector('[data-notification-page-item], .notification-history-empty')) {
                            const empty = document.createElement('div');
                            empty.className = 'empty-state notification-history-empty';
                            empty.append(textElement('h2', 'No notifications found'), textElement('p', 'Try another category or check back later.'));
                            list.append(empty);
                        }
                    });
                }
                const affected = number(payload.affected);
                if (page) {
                    const deltas = action === 'mark_all_read'
                        ? { unread: -affected, read: affected }
                        : { total: -affected, read: -affected };
                    updatePageMetrics(metricsFromPayload(payload), deltas, page);
                }
                applyPayload(payload);
                channel?.postMessage({ type: 'changed', scopeUserId });
                setRequestStatus(form, affected === 1 ? '1 notification updated.' : `${affected} notifications updated.`);
            } catch (error) {
                setRequestStatus(form, error.message || 'Unable to update notifications. Please try again.', true);
            } finally {
                setBulkPending(form, false);
            }
        });

        const refresh = async () => {
            if (!countUrl || document.hidden || refreshInFlight) return;
            refreshInFlight = true;
            try {
                const refreshUrl = new URL(countUrl, window.location.href);
                const filter = pages[0]?.dataset.notificationTypeFilter || '';
                if (filter) refreshUrl.searchParams.set('type', filter);
                if (pages.length) refreshUrl.searchParams.set('include', 'page');
                else if (centers.length) refreshUrl.searchParams.set('include', 'panel');
                const response = await fetch(refreshUrl.href, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    cache: 'no-store',
                });
                const payload = await readJsonResponse(response, 'Unable to refresh notifications.');
                applyPayload(payload);
                syncCenterNotifications(payload.notifications);
                syncPageNotifications(payload.notifications, metricsFromPayload(payload));
            } catch (_) {
                // Polling is an enhancement; no UI error is needed for a transient failure.
            } finally {
                refreshInFlight = false;
            }
        };

        const startPolling = () => {
            if (!pollId && !document.hidden) {
                refresh();
                pollId = window.setInterval(refresh, 30000);
            }
        };
        const stopPolling = () => {
            if (pollId) window.clearInterval(pollId);
            pollId = 0;
        };

        const channel = 'BroadcastChannel' in window ? new BroadcastChannel('freds-notifications') : null;
        if (channel) channel.onmessage = (event) => {
            if (event.data?.type === 'changed' && String(event.data.scopeUserId) === scopeUserId) refresh();
        };

        window.FredsNotifications = {
            setTotalCount: updateCounts,
            setMenuCounts: updateMenuCounts,
            applyPayload,
            syncCounts: (count, label = '', menuCounts = {}) => applyPayload({
                scope_user_id: scopeUserId,
                unread_count: count,
                unread_label: label,
                menu_counts: menuCounts,
            }),
            refresh,
        };
        document.addEventListener('visibilitychange', () => document.hidden ? stopPolling() : startPolling());
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            closePanels();
            document.querySelectorAll('[data-notification-page-dialog][open]').forEach(closePageDialog);
        });
        startPolling();
    });
})();
(function (root) {
    'use strict';

    const ICONS = {
        success: '<path d="m8 12 3 3 5-5"/><circle cx="12" cy="12" r="9"/>',
        warning: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/>',
        danger: '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/>',
        info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
    };

    const DEFAULT_TOAST_DURATION = 4000;
    const toastStates = new WeakMap();

    function clearToastTimer(toast) {
        const state = toastStates.get(toast);
        if (!state?.timer) return;
        window.clearTimeout(state.timer);
        state.timer = 0;
    }

    function scheduleToast(toast, delay) {
        const state = toastStates.get(toast);
        if (!state || state.dismissed) return;
        state.remaining = Math.max(0, delay);
        state.startedAt = performance.now();
        state.timer = window.setTimeout(() => dismissToast(toast), state.remaining);
        toast.dataset.toastTimer = String(state.timer);
    }

    function pauseToast(toast) {
        const state = toastStates.get(toast);
        if (!state || state.dismissed || !state.timer) return;
        state.remaining = Math.max(0, state.remaining - (performance.now() - state.startedAt));
        clearToastTimer(toast);
        toast.classList.add('is-toast-paused');
    }

    function resumeToast(toast) {
        const state = toastStates.get(toast);
        if (!state || state.dismissed || state.timer) return;
        toast.classList.remove('is-toast-paused');
        if (state.remaining <= 0) {
            dismissToast(toast);
            return;
        }
        scheduleToast(toast, state.remaining);
    }

    function dismissToast(toast) {
        if (!toast || toast.classList.contains('is-toast-leaving')) return;
        const state = toastStates.get(toast);
        if (state) state.dismissed = true;
        clearToastTimer(toast);
        toast.classList.add('is-toast-leaving');
        window.setTimeout(() => {
            const stack = toast.parentElement;
            toast.remove();
            if (stack?.classList.contains('flash-stack') && !stack.children.length) stack.remove();
        }, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 180);
    }

    function activateToast(toast) {
        if (!toast || toast.dataset.toastReady === 'true') return toast;
        toast.dataset.toastReady = 'true';
        toast.querySelector('[data-toast-close]')?.addEventListener('click', () => dismissToast(toast));
        const timeout = Math.max(1, Number(toast.dataset.toastTimeout || DEFAULT_TOAST_DURATION));
        toast.style.setProperty('--toast-duration', `${timeout}ms`);
        toastStates.set(toast, { timer: 0, remaining: timeout, startedAt: 0, dismissed: false });
        scheduleToast(toast, timeout);
        toast.addEventListener('mouseenter', () => pauseToast(toast));
        toast.addEventListener('mouseleave', () => {
            if (!toast.contains(document.activeElement)) resumeToast(toast);
        });
        toast.addEventListener('focusin', () => pauseToast(toast));
        toast.addEventListener('focusout', (event) => {
            if (!toast.contains(event.relatedTarget)) resumeToast(toast);
        });
        return toast;
    }

    function showSystemNotification(message, options) {
        const settings = options || {};
        let type = settings.type === 'error' ? 'danger' : settings.type || 'info';
        if (!ICONS[type]) type = 'info';
        let stack = document.querySelector('.flash-stack');
        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'flash-stack';
            stack.setAttribute('aria-label', 'System notifications');
            document.body.append(stack);
        }

        const toast = document.createElement('div');
        toast.className = `system-toast system-toast--${type}`;
        toast.setAttribute('role', type === 'danger' ? 'alert' : 'status');
        toast.setAttribute('aria-live', type === 'danger' ? 'assertive' : 'polite');
        toast.setAttribute('aria-atomic', 'true');
        toast.dataset.systemToast = '';
        toast.dataset.toastTimeout = String(settings.timeout ?? DEFAULT_TOAST_DURATION);

        const icon = document.createElement('span');
        icon.className = 'system-toast__icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">${ICONS[type]}</svg>`;
        const text = document.createElement('span');
        text.className = 'system-toast__message';
        const statusLabel = document.createElement('span');
        statusLabel.className = 'sr-only';
        statusLabel.textContent = `${type === 'danger' ? 'Error' : type.charAt(0).toUpperCase() + type.slice(1)}: `;
        text.append(statusLabel, document.createTextNode(String(message || '')));
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'system-toast__close';
        close.dataset.toastClose = '';
        close.setAttribute('aria-label', 'Close notification');
        close.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>';
        const progress = document.createElement('span');
        progress.className = 'system-toast__progress';
        progress.setAttribute('aria-hidden', 'true');
        const progressBar = document.createElement('span');
        progressBar.className = 'system-toast__progress-bar';
        progress.append(progressBar);
        toast.append(icon, text, close, progress);
        stack.append(toast);
        return activateToast(toast);
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-system-toast]').forEach(activateToast);
    });

    root.showSystemNotification = showSystemNotification;
    root.dismissSystemNotification = dismissToast;
})(window);
