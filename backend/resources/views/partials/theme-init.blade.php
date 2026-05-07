<script>
    (function () {
        const key = 'dms-theme-preference';
        const allowed = ['system', 'light', 'dark'];
        let preference = 'system';

        try {
            const stored = window.localStorage.getItem(key);
            if (allowed.includes(stored)) {
                preference = stored;
            }
        } catch (error) {
            preference = 'system';
        }

        const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        const resolved = preference === 'system'
            ? (prefersDark ? 'dark' : 'light')
            : preference;

        document.documentElement.dataset.themePreference = preference;
        document.documentElement.dataset.theme = resolved;
        document.documentElement.style.colorScheme = resolved;
    })();
</script>
