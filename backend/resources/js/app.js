import './bootstrap';

const THEME_STORAGE_KEY = 'dms-theme-preference';
const THEME_OPTIONS = ['system', 'light', 'dark'];
const themeMediaQuery = window.matchMedia
    ? window.matchMedia('(prefers-color-scheme: dark)')
    : null;

function normalizeThemePreference(value) {
    return THEME_OPTIONS.includes(value) ? value : 'system';
}

function getStoredThemePreference() {
    try {
        return normalizeThemePreference(window.localStorage.getItem(THEME_STORAGE_KEY));
    } catch (error) {
        return 'system';
    }
}

function resolveTheme(preference) {
    if (preference === 'system') {
        return themeMediaQuery?.matches ? 'dark' : 'light';
    }

    return preference;
}

function applyThemePreference(preference) {
    const normalizedPreference = normalizeThemePreference(preference);
    const resolvedTheme = resolveTheme(normalizedPreference);
    const root = document.documentElement;

    root.dataset.themePreference = normalizedPreference;
    root.dataset.theme = resolvedTheme;
    root.style.colorScheme = resolvedTheme;

    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        const nextTheme = resolvedTheme === 'dark' ? 'light' : 'dark';

        button.dataset.themeCurrent = resolvedTheme;
        button.setAttribute('aria-label', `Switch to ${nextTheme} theme`);
        button.setAttribute('title', `Switch to ${nextTheme} theme`);
    });
}

function persistThemePreference(preference) {
    const normalizedPreference = normalizeThemePreference(preference);

    try {
        window.localStorage.setItem(THEME_STORAGE_KEY, normalizedPreference);
    } catch (error) {
        // Ignore storage failures and still apply the current choice.
    }

    applyThemePreference(normalizedPreference);
}

function bindThemeToggles() {
    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        if (!(button instanceof HTMLButtonElement) || button.dataset.themeBound === '1') {
            return;
        }

        button.dataset.themeBound = '1';
        button.addEventListener('click', () => {
            const currentTheme = document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';
            persistThemePreference(currentTheme === 'dark' ? 'light' : 'dark');
        });
    });
}

function bootThemeSwitcher() {
    applyThemePreference(getStoredThemePreference());
    bindThemeToggles();
}

if (themeMediaQuery) {
    const handleSystemThemeChange = () => {
        if (getStoredThemePreference() === 'system') {
            applyThemePreference('system');
        }
    };

    if (typeof themeMediaQuery.addEventListener === 'function') {
        themeMediaQuery.addEventListener('change', handleSystemThemeChange);
    } else if (typeof themeMediaQuery.addListener === 'function') {
        themeMediaQuery.addListener(handleSystemThemeChange);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootThemeSwitcher);
} else {
    bootThemeSwitcher();
}
