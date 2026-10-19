(() => {
    const section = document.querySelector('.auth-records');
    if (!section) return;

    let pendingRequest = null;
    const switchTab = async (address, addHistory = true) => {
        const destination = new URL(address, window.location.href);
        destination.hash = '';
        if (destination.origin !== window.location.origin || destination.pathname !== window.location.pathname) {
            window.location.assign(address);
            return;
        }

        pendingRequest?.abort();
        const request = new AbortController();
        pendingRequest = request;
        const currentPanel = section.querySelector('.auth-records__panel');
        const savedScroll = window.scrollY;
        const savedTabsScroll = section.querySelector('[data-auth-records-tabs]')?.scrollLeft || 0;
        currentPanel?.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(destination.href, {
                credentials: 'same-origin',
                signal: request.signal,
                headers: { Accept: 'text/html' },
            });
            if (!response.ok || !response.headers.get('content-type')?.includes('text/html')
                || new URL(response.url).pathname !== destination.pathname) throw new Error('Tab request failed');

            const documentForTab = new DOMParser().parseFromString(await response.text(), 'text/html');
            const nextPanel = documentForTab.querySelector('.auth-records__panel');
            const nextMetrics = documentForTab.querySelector('.auth-records__metrics');
            if (!nextPanel || !nextMetrics || pendingRequest !== request) throw new Error('Invalid tab response');

            currentPanel?.replaceWith(nextPanel);
            document.dispatchEvent(new CustomEvent('app:date-inputs-added', { detail: { root: nextPanel } }));
            section.querySelector('.auth-records__metrics')?.replaceWith(nextMetrics);
            const newTabs = nextPanel.querySelector('[data-auth-records-tabs]');
            if (newTabs) newTabs.scrollLeft = savedTabsScroll;
            if (addHistory) window.history.pushState({ authRecordsTab: true }, '', destination.href);
            window.requestAnimationFrame(() => window.scrollTo({ top: savedScroll, behavior: 'auto' }));
            const activeTab = nextPanel.querySelector('[aria-current="page"]');
            if (activeTab) {
                try {
                    activeTab.focus({ preventScroll: true });
                } catch (_) {
                    activeTab.focus();
                }
            }
        } catch (error) {
            if (error.name !== 'AbortError' && pendingRequest === request) window.location.assign(address);
        } finally {
            if (pendingRequest === request) {
                currentPanel?.removeAttribute('aria-busy');
                pendingRequest = null;
            }
        }
    };

    section.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('[data-auth-records-tab]') : null;
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        switchTab(link.href);
    });

    const updateCustomPeriod = (event) => {
        const input = event.target instanceof Element ? event.target.closest('[data-date-display][name="from"], [data-date-display][name="to"]') : null;
        if (!input || !input.value.trim()) return;
        const period = input.closest('form')?.querySelector('select[name="period"]');
        if (period) period.value = 'custom';
    };
    section.addEventListener('input', updateCustomPeriod);
    section.addEventListener('change', (event) => {
        updateCustomPeriod(event);
        const period = event.target instanceof Element ? event.target.closest('select[name="period"]') : null;
        if (!period || period.value === 'custom') return;
        period.closest('form')?.querySelectorAll('[data-date-display][name="from"], [data-date-display][name="to"]').forEach((input) => {
            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });

    window.addEventListener('popstate', () => {
        if (window.location.pathname === new URL(section.baseURI).pathname) {
            switchTab(window.location.href, false);
        }
    });
})();
