(() => {
    const storageKey = 'freds-color-theme-v2';

    try {
        const savedTheme = localStorage.getItem(storageKey);
        const preferredTheme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
            ? 'dark'
            : 'light';
        document.documentElement.dataset.theme = savedTheme === 'dark' || savedTheme === 'light'
            ? savedTheme
            : preferredTheme;
    } catch (error) {
        document.documentElement.dataset.theme = 'light';
    }
})();
