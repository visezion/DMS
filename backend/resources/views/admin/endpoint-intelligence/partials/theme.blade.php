<style>
    .endpoint-intelligence-shell {
        --ei-border: #d7deea;
        --ei-border-strong: #d7deea;
        --ei-panel-bg: #ffffff;
        --ei-sub-bg: var(--brand-primary-soft);
        --ei-accent: var(--brand-primary);
        --ei-accent-soft: var(--brand-primary-soft);
        --ei-accent-alt: var(--brand-accent, var(--brand-primary));
        --ei-surface-muted: var(--brand-background);
        position: relative;
    }

    html[data-theme="dark"] .endpoint-intelligence-shell {
        --ei-border: #334155;
        --ei-border-strong: #475569;
        --ei-panel-bg: #111c2d;
        --ei-sub-bg: rgba(14, 165, 233, 0.12);
        --ei-surface-muted: #0f172a;
    }

    .endpoint-intelligence-header {
        border-radius: var(--brand-radius-3xl);
        border: 1px solid var(--ei-border);
        background: #ffffff;
    }

    html[data-theme="dark"] .endpoint-intelligence-header {
        background: var(--ei-panel-bg);
    }

    .endpoint-intelligence-header__layout {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        gap: 1.25rem;
        align-items: start;
    }

    .endpoint-intelligence-header__icon {
        display: flex;
        height: 3.5rem;
        width: 3.5rem;
        align-items: center;
        justify-content: center;
        border: 1px solid var(--ei-border);
        border-radius: var(--brand-radius-2xl);
        background: var(--brand-primary-soft);
        color: var(--brand-primary);
    }

    .endpoint-intelligence-header__body {
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 0.9rem;
    }

    .endpoint-intelligence-header__copy {
        min-width: 0;
    }

    .endpoint-intelligence-header__eyebrow {
        color: var(--brand-primary);
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.24em;
        text-transform: uppercase;
    }

    .endpoint-intelligence-header__title {
        margin-top: 0.35rem;
        color: rgb(15 23 42);
        font-size: clamp(1.7rem, 2.4vw, 2.25rem);
        font-weight: 700;
        letter-spacing: -0.03em;
        line-height: 1.08;
    }

    .endpoint-intelligence-header__description {
        margin-top: 0.8rem;
        max-width: 58rem;
        color: rgb(71 85 105);
        font-size: 0.98rem;
        line-height: 1.7;
    }

    .endpoint-intelligence-header__meta {
        display: inline-flex;
        width: fit-content;
        max-width: 100%;
        align-items: center;
        gap: 0.65rem;
        border: 1px solid var(--ei-border);
        border-radius: 9999px;
        background: var(--brand-background);
        padding: 0.7rem 1rem;
        color: rgb(51 65 85);
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.18em;
        text-transform: uppercase;
    }

    .endpoint-intelligence-header__meta-dot {
        height: 0.55rem;
        width: 0.55rem;
        flex-shrink: 0;
        border-radius: 9999px;
        background: var(--brand-accent, var(--brand-primary));
    }

    .endpoint-intelligence-header__highlights {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        padding-top: 0.25rem;
    }

    .endpoint-intelligence-header__highlight {
        min-width: 0;
        border: 1px solid var(--ei-border);
        border-radius: 9999px;
        background: #ffffff;
        padding: 0.55rem 0.9rem;
    }

    html[data-theme="dark"] .endpoint-intelligence-header__title,
    html[data-theme="dark"] .endpoint-intelligence-header__highlight-value {
        color: rgb(248 250 252);
    }

    html[data-theme="dark"] .endpoint-intelligence-header__description,
    html[data-theme="dark"] .endpoint-intelligence-header__meta,
    html[data-theme="dark"] .endpoint-intelligence-header__highlight-label {
        color: rgb(148 163 184);
    }

    html[data-theme="dark"] .endpoint-intelligence-header__highlight {
        background: #0f172a;
    }

    .endpoint-intelligence-header__highlight-label {
        color: rgb(100 116 139);
        font-size: 0.66rem;
        font-weight: 700;
        letter-spacing: 0.16em;
        text-transform: uppercase;
    }

    .endpoint-intelligence-header__highlight-value {
        margin-top: 0.16rem;
        color: rgb(15 23 42);
        font-size: 0.8rem;
        font-weight: 600;
        line-height: 1.25;
        white-space: nowrap;
    }

    .endpoint-intelligence-shell > section,
    .endpoint-intelligence-shell > div {
        position: relative;
        z-index: 1;
    }

    .endpoint-intelligence-shell article.rounded-2xl.border.border-slate-200.bg-white,
    .endpoint-intelligence-shell section.rounded-2xl.border.border-slate-200.bg-white {
        border-color: var(--ei-border);
        background: var(--ei-panel-bg);
        border-radius: var(--brand-radius-2xl);
    }

    .endpoint-intelligence-shell .rounded-xl.border.border-slate-200.bg-slate-50 {
        border-color: var(--ei-border);
        background: var(--ei-surface-muted);
        border-radius: var(--brand-radius-xl);
    }

    .endpoint-intelligence-shell .ei-panel-title {
        letter-spacing: 0.04em;
    }

    .endpoint-intelligence-shell .ei-empty {
        color: rgb(100 116 139);
        border-style: dashed;
    }

    .endpoint-intelligence-shell .ei-code,
    .endpoint-intelligence-shell pre.rounded-xl {
        border-color: var(--ei-border);
        background: #0f172a;
        border-radius: var(--brand-radius-xl);
    }

    .endpoint-intelligence-shell table {
        border-collapse: separate;
        border-spacing: 0;
    }

    .endpoint-intelligence-shell table thead th {
        color: rgb(71 85 105);
        font-weight: 700;
        letter-spacing: 0.12em;
    }

    html[data-theme="dark"] .endpoint-intelligence-shell table thead th {
        color: rgb(148 163 184);
    }

    .endpoint-intelligence-shell table tbody tr {
        transition: background-color 120ms ease, border-color 120ms ease;
    }

    .endpoint-intelligence-shell table tbody tr:hover {
        background-color: var(--brand-primary-soft);
    }

    .endpoint-intelligence-shell table tbody td {
        border-top: 1px solid rgba(226, 232, 240, 0.95);
        border-bottom: 1px solid rgba(226, 232, 240, 0.95);
        background: #ffffff;
    }

    html[data-theme="dark"] .endpoint-intelligence-shell table tbody td {
        border-top-color: rgba(51, 65, 85, 0.95);
        border-bottom-color: rgba(51, 65, 85, 0.95);
        background: #111c2d;
        color: rgb(226 232 240);
    }

    .endpoint-intelligence-shell table tbody td:first-child {
        border-left: 1px solid rgba(226, 232, 240, 0.95);
        border-radius: var(--brand-radius-lg) 0 0 var(--brand-radius-lg);
        padding-left: 0.9rem;
    }

    .endpoint-intelligence-shell table tbody td:last-child {
        border-right: 1px solid rgba(226, 232, 240, 0.95);
        border-radius: 0 var(--brand-radius-lg) var(--brand-radius-lg) 0;
        padding-right: 0.9rem;
    }

    html[data-theme="dark"] .endpoint-intelligence-shell table tbody td:first-child {
        border-left-color: rgba(51, 65, 85, 0.95);
    }

    html[data-theme="dark"] .endpoint-intelligence-shell table tbody td:last-child {
        border-right-color: rgba(51, 65, 85, 0.95);
    }

    .endpoint-intelligence-shell button.rounded-lg,
    .endpoint-intelligence-shell button.rounded-xl,
    .endpoint-intelligence-shell a.rounded-lg,
    .endpoint-intelligence-shell a.rounded-xl {
        transition: border-color 120ms ease, background-color 120ms ease, color 120ms ease;
    }

    .endpoint-intelligence-shell button.rounded-lg:hover,
    .endpoint-intelligence-shell button.rounded-xl:hover,
    .endpoint-intelligence-shell a.rounded-lg:hover,
    .endpoint-intelligence-shell a.rounded-xl:hover {
        border-color: var(--ei-border-strong);
    }

    .endpoint-intelligence-shell .ei-link {
        color: var(--brand-primary);
    }

    .endpoint-intelligence-shell .ei-link:hover {
        color: var(--brand-accent, var(--brand-primary));
    }

    .endpoint-intelligence-shell .ei-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        border: 1px solid var(--ei-border);
        background: #ffffff;
        color: rgb(51 65 85);
        border-radius: 9999px;
    }

    html[data-theme="dark"] .endpoint-intelligence-shell .ei-chip {
        background: #0f172a;
        color: rgb(226 232 240);
    }

    .endpoint-intelligence-shell .ei-chip-primary {
        border-color: var(--brand-primary-border);
        background: var(--brand-primary-soft);
        color: var(--brand-primary);
    }

    .endpoint-intelligence-shell .ei-chip-accent {
        border-color: var(--brand-accent-border, var(--brand-primary-border));
        background: var(--brand-accent-soft, var(--brand-primary-soft));
        color: var(--brand-accent, var(--brand-primary));
    }

    .endpoint-intelligence-shell .ei-button-primary {
        border-color: var(--brand-primary);
        background: var(--brand-primary);
        color: #ffffff;
    }

    .endpoint-intelligence-shell .ei-button-primary:hover {
        background: var(--brand-accent, var(--brand-primary));
        border-color: var(--brand-accent, var(--brand-primary));
        color: #ffffff;
    }

    .endpoint-intelligence-shell .ei-button-accent {
        border-color: var(--brand-accent-border, var(--brand-primary-border));
        background: var(--brand-accent-soft, var(--brand-primary-soft));
        color: var(--brand-accent, var(--brand-primary));
    }

    .endpoint-intelligence-shell .ei-button-accent:hover {
        background: var(--brand-accent-soft-2, var(--brand-accent-soft, var(--brand-primary-soft)));
        color: var(--brand-accent, var(--brand-primary));
    }

    .endpoint-intelligence-shell .ei-message-assistant {
        background: var(--brand-primary-soft);
        border-color: var(--brand-primary-border);
    }

    .endpoint-intelligence-shell .ei-assistant-panel {
        border: 1px solid var(--ei-border);
        border-radius: var(--brand-radius-xl);
        background: #ffffff;
    }

    .endpoint-intelligence-shell .ei-assistant-summary {
        border: 1px solid var(--brand-primary-border);
        border-radius: var(--brand-radius-xl);
        background: var(--brand-primary-soft);
    }

    .endpoint-intelligence-shell .ei-assistant-grid {
        display: grid;
        gap: 0.9rem;
    }

    .endpoint-intelligence-shell .ei-assistant-list {
        display: grid;
        gap: 0.65rem;
    }

    .endpoint-intelligence-shell .ei-assistant-item {
        border: 1px solid var(--ei-border);
        border-radius: var(--brand-radius-lg);
        background: #ffffff;
        padding: 0.85rem 0.95rem;
    }

    .endpoint-intelligence-shell .ei-assistant-item-muted {
        background: var(--brand-background);
    }

    .endpoint-intelligence-shell .ei-assistant-code details {
        border: 1px solid var(--ei-border);
        border-radius: var(--brand-radius-lg);
        background: #ffffff;
    }

    html[data-theme="dark"] .endpoint-intelligence-shell .ei-assistant-panel,
    html[data-theme="dark"] .endpoint-intelligence-shell .ei-assistant-item,
    html[data-theme="dark"] .endpoint-intelligence-shell .ei-assistant-code details {
        background: #111c2d;
        color: rgb(226 232 240);
    }

    .endpoint-intelligence-shell .ei-assistant-code summary {
        cursor: pointer;
        list-style: none;
    }

    .endpoint-intelligence-shell .ei-assistant-code summary::-webkit-details-marker {
        display: none;
    }

    .endpoint-intelligence-shell .ei-progress {
        background: rgba(148, 163, 184, 0.18);
    }

    .endpoint-intelligence-shell .ei-smart-nav__head {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.85rem;
    }

    .endpoint-intelligence-shell .ei-smart-nav__eyebrow {
        color: var(--brand-primary);
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.18em;
        text-transform: uppercase;
    }

    .endpoint-intelligence-shell .ei-smart-nav__title {
        margin-top: 0.2rem;
        color: rgb(15 23 42);
        font-size: 1rem;
        font-weight: 700;
    }

    .endpoint-intelligence-shell .ei-smart-nav__tabs {
        display: flex;
        gap: 0.7rem;
        overflow-x: auto;
        padding: 0.95rem 0 0.2rem;
        scrollbar-width: thin;
    }

    .endpoint-intelligence-shell .ei-smart-tab {
        display: inline-flex;
        align-items: center;
        gap: 0.55rem;
        white-space: nowrap;
        border: 1px solid var(--ei-border);
        border-radius: 9999px;
        background: #ffffff;
        padding: 0.7rem 0.95rem;
        color: rgb(51 65 85);
        font-size: 0.82rem;
        font-weight: 600;
    }

    .endpoint-intelligence-shell .ei-smart-tab:hover {
        border-color: var(--ei-border-strong);
        color: var(--brand-primary);
    }

    .endpoint-intelligence-shell .ei-smart-tab.is-active {
        border-color: var(--brand-primary-border);
        background: var(--brand-primary-soft);
        color: var(--brand-primary);
    }

    .endpoint-intelligence-shell .ei-smart-tab__icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .endpoint-intelligence-shell .ei-smart-nav__guide {
        margin-top: 0.95rem;
        display: grid;
        gap: 0.9rem;
        border: 1px solid var(--ei-border);
        border-radius: var(--brand-radius-xl);
        background: var(--ei-surface-muted);
        padding: 0.95rem 1rem;
    }

    .endpoint-intelligence-shell .ei-smart-nav__guide-label {
        color: rgb(100 116 139);
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.16em;
        text-transform: uppercase;
    }

    .endpoint-intelligence-shell .ei-smart-nav__guide-text {
        margin-top: 0.3rem;
        color: rgb(51 65 85);
        font-size: 0.9rem;
        line-height: 1.55;
    }

    .endpoint-intelligence-shell .ei-smart-nav__guide-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.65rem;
    }

    .endpoint-intelligence-shell select,
    .endpoint-intelligence-shell input,
    .endpoint-intelligence-shell textarea {
        border-color: rgba(148, 163, 184, 0.38);
        background: #ffffff;
        border-radius: var(--brand-radius-lg);
    }

    html[data-theme="dark"] .endpoint-intelligence-shell select,
    html[data-theme="dark"] .endpoint-intelligence-shell input,
    html[data-theme="dark"] .endpoint-intelligence-shell textarea {
        border-color: rgba(71, 85, 105, 0.9);
        background: #0f172a;
        color: rgb(226 232 240);
    }

    .endpoint-intelligence-shell select:focus,
    .endpoint-intelligence-shell input:focus,
    .endpoint-intelligence-shell textarea:focus {
        outline: none;
        border-color: var(--ei-accent);
        box-shadow: none;
    }

    .endpoint-intelligence-metrics {
        position: relative;
        z-index: 1;
    }

    .endpoint-intelligence-metrics .ei-metric-card {
        position: relative;
        overflow: hidden;
        border-color: var(--ei-border-strong);
        background: #ffffff;
        border-radius: var(--brand-radius-2xl);
    }

    html[data-theme="dark"] .endpoint-intelligence-metrics .ei-metric-card {
        background: #111c2d;
    }

    .endpoint-intelligence-metrics .ei-metric-icon {
        border: 1px solid var(--ei-border-strong);
        background: var(--brand-primary-soft);
        color: var(--ei-accent);
        border-radius: var(--brand-radius-xl);
    }

    .endpoint-intelligence-metrics .ei-metric-value {
        font-size: clamp(1.8rem, 2vw, 2.45rem);
        line-height: 1.05;
    }

    @media (max-width: 768px) {
        .endpoint-intelligence-shell .ei-smart-nav__guide {
            padding: 0.85rem;
        }

        .endpoint-intelligence-header__layout {
            grid-template-columns: 1fr;
        }

        .endpoint-intelligence-header__icon {
            height: 3rem;
            width: 3rem;
        }

        .endpoint-intelligence-shell table tbody td:first-child,
        .endpoint-intelligence-shell table tbody td:last-child {
            border-radius: 0;
        }
    }
</style>
