/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Space Grotesk', 'ui-sans-serif', 'system-ui'],
                mono: ['IBM Plex Mono', 'ui-monospace', 'SFMono-Regular'],
            },
            colors: {
                ink: '#0f172a',
                skyline: 'var(--brand-primary)',
                ember: '#f97316',
                leaf: '#16a34a',
                mist: '#e2e8f0',
            },
            boxShadow: {
                glow: '0 20px 60px rgba(14,165,233,.25)',
            },
        },
    },
    plugins: [],
};
