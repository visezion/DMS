<!DOCTYPE html>
<html lang="en">
@php
    $brandingSetting = \App\Models\ControlPlaneSetting::query()->find('ui.branding');
    $branding = is_array($brandingSetting?->value ?? null) ? (($brandingSetting->value['value'] ?? []) ?: []) : [];
    if (!is_array($branding)) {
        $branding = [];
    }
    $brandName = trim((string) ($branding['project_name'] ?? 'DMS Admin')) ?: 'DMS Admin';
    $brandTagline = trim((string) ($branding['project_tagline'] ?? 'Centralized control for Windows fleet operations')) ?: 'Centralized control for Windows fleet operations';
    $brandPrimary = strtoupper((string) ($branding['primary_color'] ?? '#0EA5E9'));
    $brandAccent = strtoupper((string) ($branding['accent_color'] ?? '#F97316'));
    $brandBackground = strtoupper((string) ($branding['background_color'] ?? '#F1F5F9'));
    $brandSidebarTint = strtoupper((string) ($branding['sidebar_tint'] ?? '#FFFFFF'));
    $brandRadiusPx = max(0, min(32, (int) ($branding['border_radius_px'] ?? 12)));
    $brandLogo = is_string($branding['logo_url'] ?? null) ? trim((string) $branding['logo_url']) : '';
    $brandFavicon = is_string($branding['favicon_url'] ?? null) ? trim((string) $branding['favicon_url']) : '';
    $topbarUser = auth()->user();
    $topbarUserName = trim((string) ($topbarUser?->name ?? 'User')) ?: 'User';
    $topbarInitial = strtoupper(substr($topbarUserName, 0, 1));
    $topbarUserAvatar = null;
    if ($topbarUser) {
        $profileSetting = \App\Models\ControlPlaneSetting::query()->find('users.profile.'.$topbarUser->id);
        $profileSettingValue = is_array($profileSetting?->value ?? null) ? ($profileSetting->value['value'] ?? []) : [];
        if (is_array($profileSettingValue) && is_string($profileSettingValue['avatar_url'] ?? null)) {
            $topbarUserAvatar = trim((string) $profileSettingValue['avatar_url']) ?: null;
        }
    }
    if (is_string($topbarUserAvatar) && $topbarUserAvatar !== '') {
        if (preg_match('/^https?:\/\//i', $topbarUserAvatar) === 1) {
            $path = parse_url($topbarUserAvatar, PHP_URL_PATH);
            $topbarUserAvatar = is_string($path) ? $path : '';
        }
        $uploadsPos = strpos($topbarUserAvatar, '/uploads/avatars/');
        if ($uploadsPos !== false) {
            $topbarUserAvatar = substr($topbarUserAvatar, $uploadsPos);
        }
        $topbarUserAvatar = '/'.ltrim($topbarUserAvatar, '/');
        if (!str_starts_with($topbarUserAvatar, '/uploads/avatars/')) {
            $topbarUserAvatar = null;
        }
    }

    $securitySettingKeys = [
        'security.production_lock_mode',
        'security.signature_bypass_enabled',
        'auth.require_mfa',
        'auth.max_login_attempts',
        'auth.lockout_minutes',
        'scripts.auto_allow_run_command_hashes',
        'scripts.allowed_sha256',
        'jobs.kill_switch',
        'jobs.max_retries',
        'jobs.base_backoff_seconds',
        'devices.delete_cleanup_before_uninstall',
        'packages.download_url_mode',
    ];
    $securitySettings = \App\Models\ControlPlaneSetting::query()
        ->whereIn('key', $securitySettingKeys)
        ->get(['key', 'value'])
        ->mapWithKeys(function ($row) {
            $val = is_array($row->value ?? null) ? ($row->value['value'] ?? null) : null;
            return [$row->key => $val];
        });
    $securityGet = function (string $key, mixed $default = null) use ($securitySettings) {
        return $securitySettings->has($key) ? $securitySettings->get($key) : $default;
    };

    $securityProductionLockMode = (bool) $securityGet('security.production_lock_mode', false);
    $securitySignatureBypassEnabled = (bool) $securityGet('security.signature_bypass_enabled', filter_var((string) env('DMS_SIGNATURE_BYPASS', 'false'), FILTER_VALIDATE_BOOL));
    $securityAuthRequireMfa = (bool) $securityGet('auth.require_mfa', false);
    $securityAuthMaxAttempts = max(1, (int) $securityGet('auth.max_login_attempts', 5));
    $securityAuthLockoutMinutes = max(1, (int) $securityGet('auth.lockout_minutes', 15));
    $securityAutoAllow = (bool) $securityGet('scripts.auto_allow_run_command_hashes', false);
    $securityAllowedHashes = $securityGet('scripts.allowed_sha256', []);
    if (!is_array($securityAllowedHashes)) {
        $securityAllowedHashes = [];
    }
    $topbarKillSwitchEnabled = (bool) $securityGet('jobs.kill_switch', false);
    $topbarKillSwitchCardClass = $topbarKillSwitchEnabled
        ? 'kill-switch-card kill-switch-card-halted'
        : 'kill-switch-card';
    $topbarKillSwitchIconClass = $topbarKillSwitchEnabled
        ? 'kill-switch-icon-shell kill-switch-icon-shell-halted'
        : 'kill-switch-icon-shell';
    $topbarKillSwitchIconTone = $topbarKillSwitchEnabled ? 'text-rose-700' : 'text-rose-600';
    $topbarKillSwitchActionChip = $topbarKillSwitchEnabled
        ? 'kill-switch-chip kill-switch-chip-restore'
        : 'kill-switch-chip kill-switch-chip-danger';
    $topbarKillSwitchStatus = $topbarKillSwitchEnabled ? 'Dispatch Halted' : 'Dispatch Live';
    $topbarKillSwitchCardStatus = $topbarKillSwitchEnabled ? 'Halted' : 'Active';
    $topbarKillSwitchActionLabel = $topbarKillSwitchEnabled ? 'Restore Dispatch' : 'Engage Kill Switch';
    $topbarKillSwitchSummary = $topbarKillSwitchEnabled
        ? 'No new commands can leave the control plane.'
        : 'One action halts all new command dispatch.';
    $topbarKillSwitchModalTitle = $topbarKillSwitchEnabled ? 'Restore Command Dispatch' : 'Engage Emergency Kill Switch';
    $topbarKillSwitchModalDescription = $topbarKillSwitchEnabled
        ? 'Release the kill switch and allow new command dispatch to continue from the control plane.'
        : 'Immediately stop all new command dispatch from the control plane until an administrator explicitly restores it.';
    $topbarKillSwitchConfirmLabel = $topbarKillSwitchEnabled ? 'Restore Dispatch' : 'Engage Kill Switch';
    $topbarKillSwitchBarClass = $topbarKillSwitchEnabled ? 'bg-rose-600' : 'bg-rose-500';
    $topbarKillSwitchBarWidth = $topbarKillSwitchEnabled ? 100 : 42;
    $securityMaxRetries = (int) $securityGet('jobs.max_retries', 3);
    $securityBaseBackoff = (int) $securityGet('jobs.base_backoff_seconds', 30);
    $securityDeleteCleanup = (bool) $securityGet('devices.delete_cleanup_before_uninstall', false);
    $securityDownloadUrlMode = (string) $securityGet('packages.download_url_mode', 'public');

    $securityAppUrl = (string) config('app.url', '');
    $securityAppDebug = (bool) config('app.debug', false);
    $securitySessionSecure = (bool) config('session.secure', false);
    $securityAppEnv = strtolower((string) config('app.env', 'local'));
    $securityHttpsConfigured = str_starts_with(strtolower($securityAppUrl), 'https://');
    $securityStaleActiveRuns = \App\Models\JobRun::query()
        ->whereIn('status', ['pending', 'acked', 'running'])
        ->where('updated_at', '<', now()->subMinutes(30))
        ->count();
    $securityRecentFailedRuns = \App\Models\JobRun::query()
        ->whereIn('status', ['failed', 'non_compliant'])
        ->where('updated_at', '>=', now()->subHours(24))
        ->count();

    $securityControls = [
        ['status' => $securityProductionLockMode ? 'good' : 'warning', 'priority' => 'high'],
        ['status' => $securitySignatureBypassEnabled ? 'warning' : 'good', 'priority' => 'critical'],
        ['status' => $securityAuthRequireMfa ? 'good' : 'warning', 'priority' => 'critical'],
        ['status' => ($securityAuthMaxAttempts <= 8 && $securityAuthLockoutMinutes >= 10) ? 'good' : 'warning', 'priority' => 'high'],
        ['status' => (! $securityAutoAllow && count($securityAllowedHashes) > 0) ? 'good' : 'warning', 'priority' => 'critical'],
        ['status' => ($securityMaxRetries >= 1 && $securityMaxRetries <= 5 && $securityBaseBackoff >= 15 && $securityBaseBackoff <= 300) ? 'good' : 'warning', 'priority' => 'medium'],
        ['status' => $securityDeleteCleanup ? 'good' : 'warning', 'priority' => 'high'],
        ['status' => $securityDownloadUrlMode === 'signed' ? 'good' : 'warning', 'priority' => 'medium'],
        ['status' => $securityHttpsConfigured ? 'good' : 'warning', 'priority' => 'high'],
        ['status' => $securityAppDebug ? 'warning' : 'good', 'priority' => 'high'],
        ['status' => $securitySessionSecure ? 'good' : 'warning', 'priority' => 'high'],
        ['status' => $securityStaleActiveRuns === 0 ? 'good' : 'warning', 'priority' => 'medium'],
        ['status' => $securityRecentFailedRuns <= 10 ? 'good' : 'warning', 'priority' => 'medium'],
        ['status' => 'info', 'priority' => 'low'],
        ['status' => $securityAppEnv === 'production' ? 'good' : 'warning', 'priority' => 'high'],
    ];
    $securityPriorityWeights = ['critical' => 25, 'high' => 15, 'medium' => 9, 'low' => 5];
    $securityTotalRiskWeight = (float) collect($securityControls)->sum(function (array $control) use ($securityPriorityWeights) {
        if (($control['status'] ?? 'info') === 'info') {
            return 0;
        }
        return $securityPriorityWeights[(string) ($control['priority'] ?? 'medium')] ?? 9;
    });
    $securityCurrentRiskWeight = (float) collect($securityControls)->sum(function (array $control) use ($securityPriorityWeights) {
        if (($control['status'] ?? '') !== 'warning') {
            return 0;
        }
        return $securityPriorityWeights[(string) ($control['priority'] ?? 'medium')] ?? 9;
    });
    $topbarSecurityScore = $securityTotalRiskWeight > 0
        ? max(0, min(100, (int) round(100 - (($securityCurrentRiskWeight / $securityTotalRiskWeight) * 100))))
        : 100;
    $topbarSecurityTone = $topbarSecurityScore >= 85
        ? ['text' => 'text-emerald-700', 'bg' => 'bg-emerald-50 border-emerald-200', 'bar' => 'bg-emerald-500']
        : ($topbarSecurityScore >= 65
            ? ['text' => 'text-amber-700', 'bg' => 'bg-amber-50 border-amber-200', 'bar' => 'bg-amber-500']
            : ['text' => 'text-rose-700', 'bg' => 'bg-rose-50 border-rose-200', 'bar' => 'bg-rose-500']);

    $aiAccuracyWindowDays = 30;
    $aiAccuracyReviewedTotal = \App\Models\BehaviorPolicyFeedback::query()
        ->whereIn('decision', ['approved', 'edited', 'rejected', 'false_positive', 'false_negative'])
        ->where('created_at', '>=', now()->subDays($aiAccuracyWindowDays))
        ->count();
    $aiAccuracyCorrectTotal = \App\Models\BehaviorPolicyFeedback::query()
        ->whereIn('decision', ['approved', 'edited'])
        ->where('created_at', '>=', now()->subDays($aiAccuracyWindowDays))
        ->count();
    $topbarAiAccuracy = $aiAccuracyReviewedTotal > 0
        ? max(0, min(100, (int) round(($aiAccuracyCorrectTotal / $aiAccuracyReviewedTotal) * 100)))
        : null;
    $topbarAiTone = $topbarAiAccuracy === null
        ? ['text' => 'text-slate-700', 'bg' => 'bg-slate-50 border-slate-200', 'bar' => 'bg-slate-400']
        : ($topbarAiAccuracy >= 85
            ? ['text' => 'text-emerald-700', 'bg' => 'bg-emerald-50 border-emerald-200', 'bar' => 'bg-emerald-500']
            : ($topbarAiAccuracy >= 65
                ? ['text' => 'text-amber-700', 'bg' => 'bg-amber-50 border-amber-200', 'bar' => 'bg-amber-500']
                : ['text' => 'text-amber-800', 'bg' => 'bg-amber-100 border-amber-300', 'bar' => 'bg-amber-500']));

    $processExistsByPattern = function (string $pattern): bool {
        if (DIRECTORY_SEPARATOR === '\\') {
            $escaped = str_replace("'", "''", $pattern);
            $cmd = 'wmic process where "Name=\'php.exe\' and CommandLine like \'%'.$escaped.'%\'" get ProcessId /value 2>NUL';
            $output = shell_exec($cmd);
            if (is_string($output) && preg_match('/ProcessId=\\d+/', $output) === 1) {
                return true;
            }

            $fallback = shell_exec('tasklist /FI "IMAGENAME eq php.exe" 2>NUL');
            return is_string($fallback) && stripos($fallback, 'php.exe') !== false;
        }

        $safe = escapeshellarg($pattern);
        $output = shell_exec('pgrep -af '.$safe.' 2>/dev/null');
        return is_string($output) && trim($output) !== '';
    };

    $aiRuntimeQueueRunning = $processExistsByPattern('artisan queue:work');
    $aiRuntimeSchedulerRunning = $processExistsByPattern('artisan schedule:work');
    $aiRuntimeRunning = $aiRuntimeQueueRunning && $aiRuntimeSchedulerRunning;

    $agentBackendHost = (string) env('AGENT_BACKEND_HOST', '127.0.0.1');
    $agentBackendPort = (int) env('AGENT_BACKEND_PORT', 8000);
    $agentErrno = 0;
    $agentErrstr = '';
    $agentConnection = @fsockopen($agentBackendHost, $agentBackendPort, $agentErrno, $agentErrstr, 1.2);
    $agentBackendRunning = is_resource($agentConnection);
    if ($agentBackendRunning) {
        @fclose($agentConnection);
    }
    $agentBackendError = $agentBackendRunning
        ? null
        : (trim($agentErrstr) !== '' ? trim($agentErrstr) : ('connect errno '.$agentErrno));

    $showRuntimePopup = ! $aiRuntimeRunning || ! $agentBackendRunning;
@endphp
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ $title ?? $brandName }}</title>
    @if($brandFavicon !== '')
        <link rel="icon" type="image/png" href="{{ $brandFavicon }}">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand-primary: {{ $brandPrimary }};
            --brand-primary-soft: {{ $brandPrimary }}1A;
            --brand-primary-soft-2: {{ $brandPrimary }}26;
            --brand-primary-border: {{ $brandPrimary }}66;
            --brand-radius-base: {{ $brandRadiusPx }}px;
            --brand-radius-sm: max(2px, calc(var(--brand-radius-base) - 4px));
            --brand-radius-md: max(4px, calc(var(--brand-radius-base) - 2px));
            --brand-radius-lg: var(--brand-radius-base);
            --brand-radius-xl: calc(var(--brand-radius-base) + 2px);
            --brand-radius-2xl: calc(var(--brand-radius-base) + 4px);
            --brand-radius-3xl: calc(var(--brand-radius-base) + 8px);
        }
        body {
            background: {{ $brandBackground }};
        }
        .glass { background: rgba(255, 255, 255, 0.1); backdrop-filter: blur(6px); }
        .modal-backdrop {
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(1px);
            -webkit-backdrop-filter: blur(1px);
        }
        .kill-switch-card {
            border: 1px solid rgba(239, 68, 68, 0.22);
            background:
                radial-gradient(circle at top right, rgba(248, 113, 113, 0.18), transparent 38%),
                linear-gradient(135deg, #fff1f2 0%, #ffffff 62%, #fff7ed 100%);
        }
        .kill-switch-card-halted {
            border-color: rgba(239, 68, 68, 0.36);
        }
        .kill-switch-icon-shell {
            border: 1px solid rgba(239, 68, 68, 0.22);
            background: rgba(254, 226, 226, 0.92);
        }
        .kill-switch-icon-shell-halted {
            background: rgba(254, 205, 211, 0.96);
        }
        .kill-switch-chip {
            border-radius: 9999px;
            padding: 0.125rem 0.55rem;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }
        .kill-switch-chip-danger {
            border: 1px solid rgba(239, 68, 68, 0.22);
            background: rgba(254, 226, 226, 0.92);
            color: #b91c1c;
        }
        .kill-switch-chip-restore {
            border: 1px solid rgba(251, 191, 36, 0.24);
            background: rgba(254, 243, 199, 0.96);
            color: #92400e;
        }
        .brand-modal-note {
            border: 1px solid rgba(239, 68, 68, 0.22);
            background: #fff1f2;
            color: #b91c1c;
        }
        .brand-modal-input:focus {
            outline: none;
            border-color: rgba(248, 113, 113, 0.4);
            box-shadow: 0 0 0 3px rgba(127, 29, 29, 0.22);
        }
        .brand-modal-action {
            background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
            color: #ffffff;
        }
        .brand-modal-action:hover {
            filter: brightness(1.04);
        }
        .nav-link { transition: all .2s ease; }
        .nav-link:hover { transform: translateX(4px); }
        /* Sidebar active item style: light panel + left accent (not solid blue pill) */
        aside nav .bg-skyline {
            background: var(--brand-primary-soft) !important;
            color: #000000 !important;
            border-left: 3px solid var(--brand-primary);
            border-radius: 0.5rem;
            padding: 0.875rem 0.75rem 0.875rem 0.75rem !important;
        }
        aside nav .bg-skyline * {
            color: inherit !important;
        }
        aside nav .border-skyline {
            border-color: var(--brand-primary-border) !important;
        }

        /* Make common Tailwind sky/blue tokens follow Branding primary color */
        .text-sky-500, .text-sky-600, .text-sky-700,
        .text-blue-500, .text-blue-600, .text-blue-700 {
            color: var(--brand-primary) !important;
        }
        .border-sky-300, .border-sky-400, .border-sky-500,
        .border-blue-300, .border-blue-400, .border-blue-500 {
            border-color: var(--brand-primary-border) !important;
        }
        .bg-sky-50, .bg-sky-100, .bg-blue-50, .bg-blue-100 {
            background-color: var(--brand-primary-soft) !important;
        }
        .bg-skyline\/10 {
            background-color: var(--brand-primary-soft) !important;
        }
        .border-skyline\/30 {
            border-color: var(--brand-primary-border) !important;
        }
        .hover\:text-skyline:hover {
            color: var(--brand-primary) !important;
        }
        .hover\:border-sky-300:hover {
            border-color: var(--brand-primary-border) !important;
        }

        /* Force menu text/icons to black */
        aside nav a,
        aside nav summary,
        aside nav a svg,
        aside nav summary svg,
        .lg\:hidden nav a,
        .lg\:hidden nav summary,
        .lg\:hidden nav a svg,
        .lg\:hidden nav summary svg,
        header nav[aria-label="Top shortcuts"] a,
        header nav[aria-label="Top shortcuts"] a svg {
            color: #000 !important;
        }

        /* Global corner radius from Branding */
        .rounded:not(.rounded-full) { border-radius: var(--brand-radius-sm) !important; }
        .rounded-sm:not(.rounded-full) { border-radius: var(--brand-radius-sm) !important; }
        .rounded-md:not(.rounded-full) { border-radius: var(--brand-radius-md) !important; }
        .rounded-lg:not(.rounded-full) { border-radius: var(--brand-radius-lg) !important; }
        .rounded-xl:not(.rounded-full) { border-radius: var(--brand-radius-xl) !important; }
        .rounded-2xl:not(.rounded-full) { border-radius: var(--brand-radius-2xl) !important; }
        .rounded-3xl:not(.rounded-full) { border-radius: var(--brand-radius-3xl) !important; }

        .expand-indicator::before { content: '+'; }
        details[open] > summary .expand-indicator::before { content: '-'; }
    </style>
</head>
<body class="min-h-screen text-ink">
<div id="admin-shell" class="flex min-h-screen">
    <aside class="w-72 hidden lg:flex lg:flex-col border-r border-slate-200/60 glass" style="background: {{ $brandSidebarTint }}CC;">
        <div class="px-6 py-4 border-b border-slate-200/60">
            <div class="flex items-center gap-3">
                @if($brandLogo !== '')
                    <img src="{{ $brandLogo }}" alt="Brand Logo" class="h-14 w-auto max-w-[12rem] object-contain">
                @else
                    <div class="h-14 w-14 rounded-full flex items-center justify-center text-slate-700" aria-label="Brand Logo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-7 h-7">
                            <path d="M12 3 5 6v6c0 4.5 3 7.7 7 9 4-1.3 7-4.5 7-9V6l-7-3Z"/>
                            <path d="m9 12 2 2 4-4"/>
                        </svg>
                    </div>
                @endif
            </div>
        </div>
        <nav class="px-4 py-3 space-y-3.5 text-sm font-medium">
            <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.enroll-devices*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.enroll-devices') }}">Enroll Devices</a>
            <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.dashboard') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.dashboard') }}">Overview</a>
            <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.devices') || request()->routeIs('admin.devices.show') || request()->routeIs('admin.devices.live') || request()->routeIs('admin.devices.update') || request()->routeIs('admin.devices.delete') || request()->routeIs('admin.devices.reenroll') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.devices') }}">Devices</a>
            <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.groups*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.groups') }}">Groups</a>
            <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.packages*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.packages') }}">Software Packages</a>

            <details class="pt-2 group" {{ request()->routeIs('admin.policies*') || request()->routeIs('admin.catalog*') || request()->routeIs('admin.policy-categories*') ? 'open' : '' }}>
                <summary class="list-none cursor-pointer rounded-lg px-3 py-1.5 flex items-center justify-between {{ request()->routeIs('admin.policies*') || request()->routeIs('admin.catalog*') || request()->routeIs('admin.policy-categories*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}">
                    <span>Policy Center</span>
                    <span class="expand-indicator text-xs"></span>
                </summary>
                <div class="mt-3 pl-2 space-y-2">
                    <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.policies*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.policies') }}">Policies</a>
                    <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.catalog*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.catalog') }}">Policy Catalog</a>
                    <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.policy-categories*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.policy-categories') }}">Policy Categories</a>
                </div>
            </details>
            <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.jobs*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.jobs') }}">Jobs</a>
            <details class="pt-2 group" {{ request()->routeIs('admin.behavior-ai*') || request()->routeIs('admin.behavior-baseline*') || request()->routeIs('admin.behavior-remediation*') ? 'open' : '' }}>
                <summary class="list-none cursor-pointer rounded-lg px-3 py-1.5 flex items-center justify-between {{ request()->routeIs('admin.behavior-ai*') || request()->routeIs('admin.behavior-baseline*') || request()->routeIs('admin.behavior-remediation*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}">
                    <span class="flex items-center gap-2">
                        <span aria-hidden="true" class="text-current">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                                <path d="M4 6h16M4 12h10M4 18h7"></path>
                                <circle cx="17" cy="12" r="3"></circle>
                            </svg>
                        </span>
                        <span class="flex items-center gap-2">
                            <span>Behaviour Center</span>
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.2em] {{ request()->routeIs('admin.behavior-ai*') || request()->routeIs('admin.behavior-baseline*') || request()->routeIs('admin.behavior-remediation*') ? 'bg-white/20 text-white' : 'bg-emerald-100 text-emerald-700' }}">
                                WIP
                            </span>
                        </span>
                    </span>
                    <span class="expand-indicator text-xs"></span>
                </summary>
                <div class="mt-3 pl-2 space-y-2">
                    <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.behavior-ai*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }} flex items-center gap-2" href="{{ route('admin.behavior-ai.index') }}">
                        <span aria-hidden="true" class="text-current">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                                <rect x="7" y="7" width="10" height="10" rx="2"></rect>
                                <path d="M10 10h4v4h-4zM9 3v2M15 3v2M9 19v2M15 19v2M3 9h2M3 15h2M19 9h2M19 15h2"></path>
                            </svg>
                        </span>
                        <span>AI Control Center</span>
                    </a>
                    <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.behavior-baseline*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }} flex items-center gap-2" href="{{ route('admin.behavior-baseline.index') }}">
                        <span aria-hidden="true" class="text-current">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                                <path d="M4 12h16M12 4v16"></path>
                                <circle cx="12" cy="12" r="8"></circle>
                            </svg>
                        </span>
                        <span>Behavioral Baseline</span>
                    </a>
                    <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.behavior-remediation*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }} flex items-center gap-2" href="{{ route('admin.behavior-remediation.index') }}">
                        <span aria-hidden="true" class="text-current">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                                <path d="M12 3 5 6v6c0 4.5 3 7.7 7 9 4-1.3 7-4 7-9V6l-7-3Z"></path>
                                <path d="M8 12h8M12 8v8"></path>
                            </svg>
                        </span>
                        <span>Autonomous Remediation</span>
                    </a>
                </div>
            </details>
            <details class="pt-2 group" {{ request()->routeIs('admin.agent*') || request()->routeIs('admin.ip-deploy*') ? 'open' : '' }}>
                <summary class="list-none cursor-pointer rounded-lg px-3 py-1.5 flex items-center justify-between {{ request()->routeIs('admin.agent*') || request()->routeIs('admin.ip-deploy*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}">
                    <span>Deployment Center</span>
                    <span class="expand-indicator text-xs"></span>
                </summary>
                <div class="mt-3 pl-2 space-y-2">
                    <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.agent*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.agent') }}">Agent Delivery</a>
                    <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.ip-deploy*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.ip-deploy') }}">IP Deployment</a>
                </div>
            </details>
            <details class="pt-2 group" {{ request()->routeIs('admin.settings*') || request()->routeIs('admin.security-hardening*') || request()->routeIs('admin.security-command-center*') ? 'open' : '' }}>
                <summary class="list-none cursor-pointer rounded-lg px-3 py-1.5 flex items-center justify-between {{ request()->routeIs('admin.settings*') || request()->routeIs('admin.security-hardening*') || request()->routeIs('admin.security-command-center*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}">
                    <span>Settings</span>
                    <span class="expand-indicator text-xs"></span>
                </summary>
                <div class="mt-3 pl-2 space-y-2">
                    <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.settings') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.settings') }}">General</a>
                    <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.security-hardening*') || request()->routeIs('admin.security-command-center*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }} flex items-center gap-2" href="{{ route('admin.security-hardening') }}" data-iconized="1"><span aria-hidden="true" class="text-current"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3 5 6v6c0 5 3 7.7 7 9 4-1.3 7-4 7-9V6l-7-3Z"></path><path d="m9.5 12 1.8 1.8L14.8 10"></path></svg></span><span>Security Hardening</span></a>
                    <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.settings.branding*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.settings.branding') }}">Branding</a>
                </div>
            </details>
            <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.access*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.access') }}">Access Control</a>
            <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.docs*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.docs') }}">Docs</a>
            <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.notes*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }} flex items-center gap-2" href="{{ route('admin.notes') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4" aria-hidden="true">
                    <path d="M7 3h7l5 5v13H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/>
                    <path d="M14 3v5h5"/>
                    <path d="M9 13h6M9 17h6"/>
                </svg>
                <span>Admin Notes</span>
            </a>
            <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.audit*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.audit') }}">Audit Logs</a>
        </nav>
    </aside>

    <main class="flex-1">
        <header class="px-5 lg:px-8 py-2 border-b border-slate-200 bg-white/95 backdrop-blur flex items-center justify-between sticky top-0 z-20 shadow-[0_1px_0_rgba(15,23,42,.06)]">
            <div class="flex items-center gap-3 lg:hidden">
                <button
                    type="button"
                    id="mobile-nav-open"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 shadow-sm"
                    aria-label="Open menu"
                    aria-controls="mobile-nav-overlay"
                    aria-expanded="false"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5">
                        <path d="M4 7h16M4 12h16M4 17h16"></path>
                    </svg>
                </button>
                <div class="min-w-0">
                    <p class="text-[10px] uppercase tracking-[0.22em] text-slate-500">Admin</p>
                    <p class="truncate text-sm font-semibold text-slate-900">{{ $heading ?? $title ?? $brandName }}</p>
                </div>
            </div>
            <div class="hidden lg:flex items-center gap-2">
                <a href="{{ route('admin.security-hardening') }}" class="flex w-[198px] items-center gap-2 rounded-xl border bg-white px-3 py-2 shadow-sm" title="Open Security Hardening">
                    <span class="h-8 w-8 rounded-lg border {{ $topbarSecurityTone['bg'] }} flex items-center justify-center">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4 {{ $topbarSecurityTone['text'] }}">
                            <path d="M12 3 5 6v6c0 4.5 3 7.7 7 9 4-1.3 7-4.5 7-9V6l-7-3Z"/>
                            <path d="m9 12 2 2 4-4"/>
                        </svg>
                    </span>
                    <div class="min-w-0 flex-1 leading-tight">
                        <p class="text-[10px] uppercase tracking-wide text-slate-500">Security Score</p>
                        <div class="mt-0.5 flex items-center gap-2">
                            <p class="text-2xl font-semibold text-slate-900 leading-none">{{ $topbarSecurityScore }}%</p>
                            <div class="h-1.5 flex-1 rounded-full bg-slate-200 overflow-hidden">
                                <div class="h-full {{ $topbarSecurityTone['bar'] }}" style="width: {{ $topbarSecurityScore }}%"></div>
                            </div>
                        </div>
                    </div>
                </a>
                <button
                    type="button"
                    class="flex w-[198px] items-center gap-2 rounded-xl px-3 py-2 text-left {{ $topbarKillSwitchCardClass }}"
                    title="{{ $topbarKillSwitchModalTitle }}"
                    data-kill-switch-trigger="1"
                    data-kill-switch-enabled="{{ $topbarKillSwitchEnabled ? '0' : '1' }}"
                    data-kill-switch-title="{{ $topbarKillSwitchModalTitle }}"
                    data-kill-switch-description="{{ $topbarKillSwitchModalDescription }}"
                    data-kill-switch-confirm="{{ $topbarKillSwitchConfirmLabel }}"
                >
                    <span class="h-8 w-8 rounded-lg flex items-center justify-center {{ $topbarKillSwitchIconClass }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4 {{ $topbarKillSwitchIconTone }}">
                            <path d="M12 3v7"></path>
                            <path d="M7.8 6.8a7 7 0 1 0 8.4 0"></path>
                        </svg>
                    </span>
                    <div class="min-w-0 flex-1 leading-tight">
                        <p class="text-[10px] uppercase tracking-wide text-rose-700">Kill Switch</p>
                        <div class="mt-0.5 flex items-center gap-2">
                            <p class="text-2xl font-semibold text-slate-900 leading-none">{{ $topbarKillSwitchCardStatus }}</p>
                            <div class="h-1.5 flex-1 rounded-full bg-rose-100 overflow-hidden">
                                <div class="h-full {{ $topbarKillSwitchBarClass }}" style="width: {{ $topbarKillSwitchBarWidth }}%"></div>
                            </div>
                        </div>
                    </div>
                </button>
                <a href="{{ route('admin.behavior-ai.index') }}" class="flex w-[198px] items-center gap-2 rounded-xl border bg-white px-3 py-2 shadow-sm" title="Open AI Control Center">
                    <span class="h-8 w-8 rounded-lg border {{ $topbarAiTone['bg'] }} flex items-center justify-center">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4 {{ $topbarAiTone['text'] }}">
                            <rect x="7" y="7" width="10" height="10" rx="2"></rect>
                            <path d="M10 10h4v4h-4zM9 3v2M15 3v2M9 19v2M15 19v2M3 9h2M3 15h2M19 9h2M19 15h2"></path>
                        </svg>
                    </span>
                    <div class="min-w-0 flex-1 leading-tight">
                        <p class="text-[10px] uppercase tracking-wide text-slate-500">AI Accuracy ({{ $aiAccuracyWindowDays }}d)</p>
                        <div class="mt-0.5 flex items-center gap-2">
                            <p class="text-2xl font-semibold text-slate-900 leading-none">{{ $topbarAiAccuracy !== null ? $topbarAiAccuracy.'%' : 'N/A' }}</p>
                            <div class="h-1.5 flex-1 rounded-full bg-slate-200 overflow-hidden">
                                <div class="h-full {{ $topbarAiTone['bar'] }}" style="width: {{ $topbarAiAccuracy ?? 0 }}%"></div>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="flex items-center gap-2">
                <nav class="hidden md:flex items-center gap-1.5 px-0 py-0" aria-label="Top shortcuts">
                    <a href="{{ route('admin.enroll-devices') }}" class="h-9 w-9 rounded-full flex items-center justify-center text-slate-600 hover:text-skyline transition {{ request()->routeIs('admin.enroll-devices*') ? 'text-skyline' : '' }}" title="Enroll Devices" aria-label="Enroll Devices">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5"><rect x="3" y="4" width="18" height="14" rx="2"/><path d="M7 20h10"/><path d="m9 11 2 2 4-4"/></svg>
                    </a>
                    <a href="{{ route('admin.devices') }}" class="h-9 w-9 rounded-full flex items-center justify-center text-slate-600 hover:text-skyline transition {{ request()->routeIs('admin.devices*') ? 'text-skyline' : '' }}" title="Devices" aria-label="Devices">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5"><rect x="4" y="3" width="16" height="12" rx="2"/><path d="M8 21h8M12 15v6"/></svg>
                    </a>
                    <a href="{{ route('admin.policies') }}" class="h-9 w-9 rounded-full flex items-center justify-center text-slate-600 hover:text-skyline transition {{ request()->routeIs('admin.policies*') || request()->routeIs('admin.catalog*') || request()->routeIs('admin.policy-categories*') ? 'text-skyline' : '' }}" title="Policies" aria-label="Policies">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5"><path d="M12 3v18"/><path d="M6 7h12"/><path d="M6 17h12"/><path d="M8.5 7a3.5 3.5 0 0 1 0 7"/><path d="M15.5 17a3.5 3.5 0 0 0 0-7"/></svg>
                    </a>
                    <a href="{{ route('admin.packages') }}" class="h-9 w-9 rounded-full flex items-center justify-center text-slate-600 hover:text-skyline transition {{ request()->routeIs('admin.packages*') ? 'text-skyline' : '' }}" title="Software Packages" aria-label="Software Packages">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5"><path d="M12 3 4 7l8 4 8-4-8-4Z"/><path d="M4 7v10l8 4 8-4V7"/></svg>
                    </a>
                    <a href="{{ route('admin.jobs') }}" class="h-9 w-9 rounded-full flex items-center justify-center text-slate-600 hover:text-skyline transition {{ request()->routeIs('admin.jobs*') ? 'text-skyline' : '' }}" title="Jobs" aria-label="Jobs">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>
                    </a>
                    <button
                        type="button"
                        class="hidden h-9 w-9 rounded-full items-center justify-center transition md:flex lg:hidden {{ $topbarKillSwitchEnabled ? 'text-rose-700 hover:text-rose-800' : 'text-rose-600 hover:text-rose-700' }}"
                        title="Kill Switch: {{ $topbarKillSwitchStatus }}"
                        aria-label="Kill Switch"
                        data-kill-switch-trigger="1"
                        data-kill-switch-enabled="{{ $topbarKillSwitchEnabled ? '0' : '1' }}"
                        data-kill-switch-title="{{ $topbarKillSwitchModalTitle }}"
                        data-kill-switch-description="{{ $topbarKillSwitchModalDescription }}"
                        data-kill-switch-confirm="{{ $topbarKillSwitchConfirmLabel }}"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5"><path d="M12 3v7"/><path d="M7.8 6.8a7 7 0 1 0 8.4 0"/></svg>
                    </button>
                    <a href="{{ route('admin.settings') }}" class="h-9 w-9 rounded-full flex items-center justify-center text-slate-600 hover:text-skyline transition {{ request()->routeIs('admin.settings*') ? 'text-skyline' : '' }}" title="Settings" aria-label="Settings">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5"><path d="M10.3 3h3.4l.6 2.2a7.8 7.8 0 0 1 1.8.8l2-1.1 2.4 2.4-1.1 2a7.8 7.8 0 0 1 .8 1.8l2.2.6v3.4l-2.2.6a7.8 7.8 0 0 1-.8 1.8l1.1 2-2.4 2.4-2-1.1a7.8 7.8 0 0 1-1.8.8l-.6 2.2h-3.4l-.6-2.2a7.8 7.8 0 0 1-1.8-.8l-2 1.1-2.4-2.4 1.1-2a7.8 7.8 0 0 1-.8-1.8L3 13.7v-3.4l2.2-.6a7.8 7.8 0 0 1 .8-1.8l-1.1-2 2.4-2.4 2 1.1a7.8 7.8 0 0 1 1.8-.8l.6-2.2Z"/><circle cx="12" cy="12" r="3"/></svg>
                    </a>
                </nav>
                <button
                    type="button"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-full md:hidden {{ $topbarKillSwitchEnabled ? 'text-rose-700' : 'text-rose-600' }}"
                    title="Kill Switch: {{ $topbarKillSwitchStatus }}"
                    aria-label="Kill Switch"
                    data-kill-switch-trigger="1"
                    data-kill-switch-enabled="{{ $topbarKillSwitchEnabled ? '0' : '1' }}"
                    data-kill-switch-title="{{ $topbarKillSwitchModalTitle }}"
                    data-kill-switch-description="{{ $topbarKillSwitchModalDescription }}"
                    data-kill-switch-confirm="{{ $topbarKillSwitchConfirmLabel }}"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5"><path d="M12 3v7"/><path d="M7.8 6.8a7 7 0 1 0 8.4 0"/></svg>
                </button>
                <div class="relative" id="topbar-profile-root">
                    <button type="button" id="topbar-profile-btn" class="flex items-center rounded-full bg-white border border-slate-200 p-0.5 hover:bg-slate-50 shadow-sm">
                        @if($topbarUserAvatar)
                            <img src="{{ asset(ltrim($topbarUserAvatar, '/')) }}" alt="Profile" class="h-8 w-8 rounded-full object-cover border border-slate-200" onerror="this.style.display='none'; this.nextElementSibling?.classList.remove('hidden');">
                            <span class="hidden h-8 w-8 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center text-xs font-semibold">{{ $topbarInitial }}</span>
                        @else
                            <span class="h-8 w-8 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center text-xs font-semibold">{{ $topbarInitial }}</span>
                        @endif
                    </button>
                    <div id="topbar-profile-menu" class="hidden absolute right-0 mt-2 w-56 rounded-xl border border-slate-200 bg-white shadow-xl z-50">
                        <div class="px-3 py-2 border-b border-slate-200">
                            <p class="text-sm font-medium text-slate-800 truncate">{{ $topbarUserName }}</p>
                            <p class="text-xs text-slate-500 truncate">{{ $topbarUser?->email }}</p>
                        </div>
                    <div class="p-1">
                            <a href="{{ route('admin.profile') }}" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">Profile</a>
                            <a href="{{ route('admin.settings') }}" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">Settings</a>
                            <a href="{{ route('admin.security-hardening') }}" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">Security Hardening</a>
                            <a href="{{ route('admin.docs') }}" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">Documentation</a>
                            <a href="{{ route('admin.notes') }}" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">Admin Notes</a>
                            <form method="POST" action="{{ route('admin.logout') }}">
                                @csrf
                                <button type="submit" class="w-full text-left rounded-lg px-3 py-2 text-sm text-rose-700 hover:bg-rose-50">Logout</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        <div id="mobile-nav-overlay" class="fixed inset-0 z-40 hidden lg:hidden">
            <button type="button" class="absolute inset-0 bg-slate-900/45 backdrop-blur-sm" data-mobile-nav-close aria-label="Close menu"></button>
            <aside id="mobile-nav-drawer" class="absolute inset-y-0 left-0 flex h-full w-[86vw] max-w-sm flex-col border-r border-slate-200/70 shadow-2xl" style="background: {{ $brandSidebarTint }};">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200/70 px-4 py-4">
                    <div class="min-w-0">
                        <p class="text-[10px] uppercase tracking-[0.22em] text-slate-500">Navigation</p>
                        <p class="truncate text-base font-semibold text-slate-900">{{ $brandName }}</p>
                    </div>
                    <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 shadow-sm" data-mobile-nav-close aria-label="Close menu">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5">
                            <path d="M6 6l12 12M18 6 6 18"></path>
                        </svg>
                    </button>
                </div>
                <nav id="mobile-nav-scroll" class="flex-1 overflow-y-auto px-4 py-4 space-y-3.5 text-sm font-medium">
                    <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.enroll-devices*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.enroll-devices') }}">Enroll Devices</a>
                    <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.dashboard') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.dashboard') }}">Overview</a>
                    <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.devices') || request()->routeIs('admin.devices.show') || request()->routeIs('admin.devices.live') || request()->routeIs('admin.devices.update') || request()->routeIs('admin.devices.delete') || request()->routeIs('admin.devices.reenroll') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.devices') }}">Devices</a>
                    <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.groups*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.groups') }}">Groups</a>
                    <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.packages*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.packages') }}">Software Packages</a>

                    <details class="pt-1 group" {{ request()->routeIs('admin.policies*') || request()->routeIs('admin.catalog*') || request()->routeIs('admin.policy-categories*') ? 'open' : '' }}>
                        <summary class="list-none cursor-pointer rounded-lg px-3 py-2 flex items-center justify-between {{ request()->routeIs('admin.policies*') || request()->routeIs('admin.catalog*') || request()->routeIs('admin.policy-categories*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}">
                            <span>Policy Center</span>
                            <span class="expand-indicator text-xs"></span>
                        </summary>
                        <div class="mt-3 pl-2 space-y-2">
                            <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.policies*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.policies') }}">Policies</a>
                            <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.catalog*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.catalog') }}">Policy Catalog</a>
                            <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.policy-categories*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.policy-categories') }}">Policy Categories</a>
                        </div>
                    </details>

                    <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.jobs*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.jobs') }}">Jobs</a>

                    <details class="pt-1 group" {{ request()->routeIs('admin.behavior-ai*') || request()->routeIs('admin.behavior-baseline*') || request()->routeIs('admin.behavior-remediation*') ? 'open' : '' }}>
                        <summary class="list-none cursor-pointer rounded-lg px-3 py-2 flex items-center justify-between {{ request()->routeIs('admin.behavior-ai*') || request()->routeIs('admin.behavior-baseline*') || request()->routeIs('admin.behavior-remediation*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}">
                            <span>Behaviour Center</span>
                            <span class="expand-indicator text-xs"></span>
                        </summary>
                        <div class="mt-3 pl-2 space-y-2">
                            <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.behavior-ai*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }} flex items-center gap-2" href="{{ route('admin.behavior-ai.index') }}">
                                <span>AI Control Center</span>
                            </a>
                            <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.behavior-baseline*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }} flex items-center gap-2" href="{{ route('admin.behavior-baseline.index') }}">
                                <span>Behavioral Baseline</span>
                            </a>
                            <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.behavior-remediation*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }} flex items-center gap-2" href="{{ route('admin.behavior-remediation.index') }}">
                                <span>Autonomous Remediation</span>
                            </a>
                        </div>
                    </details>

                    <details class="pt-1 group" {{ request()->routeIs('admin.agent*') || request()->routeIs('admin.ip-deploy*') ? 'open' : '' }}>
                        <summary class="list-none cursor-pointer rounded-lg px-3 py-2 flex items-center justify-between {{ request()->routeIs('admin.agent*') || request()->routeIs('admin.ip-deploy*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}">
                            <span>Deployment Center</span>
                            <span class="expand-indicator text-xs"></span>
                        </summary>
                        <div class="mt-3 pl-2 space-y-2">
                            <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.agent*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.agent') }}">Agent Delivery</a>
                            <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.ip-deploy*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.ip-deploy') }}">IP Deployment</a>
                        </div>
                    </details>

                    <details class="pt-1 group" {{ request()->routeIs('admin.settings*') || request()->routeIs('admin.security-hardening*') || request()->routeIs('admin.security-command-center*') ? 'open' : '' }}>
                        <summary class="list-none cursor-pointer rounded-lg px-3 py-2 flex items-center justify-between {{ request()->routeIs('admin.settings*') || request()->routeIs('admin.security-hardening*') || request()->routeIs('admin.security-command-center*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}">
                            <span>Settings</span>
                            <span class="expand-indicator text-xs"></span>
                        </summary>
                        <div class="mt-3 pl-2 space-y-2">
                            <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.settings') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.settings') }}">General</a>
                            <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.security-hardening*') || request()->routeIs('admin.security-command-center*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.security-hardening') }}">Security Hardening</a>
                            <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.settings.branding*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.settings.branding') }}">Branding</a>
                        </div>
                    </details>

                    <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.access*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.access') }}">Access Control</a>
                    <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.docs*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.docs') }}">Docs</a>
                    <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.notes*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.notes') }}">Notes</a>
                    <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.audit*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.audit') }}">Audit Logs</a>
                </nav>
                <div class="border-t border-slate-200/70 px-4 py-4">
                    <a href="{{ route('admin.profile') }}" class="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-700 shadow-sm">
                        <span>Open Profile</span>
                        <span class="text-xs text-slate-400">{{ $topbarUserName }}</span>
                    </a>
                </div>
            </aside>
        </div>

        <section class="p-5 lg:p-8 space-y-4">
            @if(session('status'))
                <div class="rounded-xl border border-leaf/25 bg-leaf/10 px-4 py-3 text-sm text-green-900">{{ session('status') }}</div>
            @endif
            @if($errors->any())
                <div class="rounded-xl border border-ember/30 bg-ember/10 px-4 py-3 text-sm text-amber-900">
                    {{ $errors->first() }}
                </div>
            @endif

            {{ $slot }}
        </section>
    </main>
    </div>
    <div id="runtime-alert-popup" class="@if(! $showRuntimePopup) hidden @endif modal-backdrop fixed inset-0 z-[110] flex items-center justify-center p-4">
        <div class="w-full max-w-lg rounded-3xl border border-amber-300 bg-amber-50 p-5 shadow-2xl">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs uppercase tracking-wide text-amber-700">Runtime Notification</p>
                    <h4 class="text-base font-semibold text-amber-900">Required services are not running</h4>
                </div>
                <button type="button" id="runtime-alert-close" class="rounded-md border border-amber-200 bg-white px-2 py-0.5 text-xs text-amber-700">x</button>
            </div>
            <div class="mt-4 space-y-3">
                <div id="runtime-alert-ai-card" class="@if($aiRuntimeRunning) hidden @endif rounded-xl border border-amber-200 bg-white p-4">
                    <p class="text-sm font-medium text-slate-900">Runtime Control</p>
                    <p class="mt-1 text-xs text-slate-600">Start worker and scheduler when they are offline.</p>
                    <div class="mt-2 space-y-1 text-xs text-slate-700">
                        <p>Queue: <span id="global-runtime-queue-text">{{ $aiRuntimeQueueRunning ? 'running' : 'not running' }}</span></p>
                        <p>Scheduler: <span id="global-runtime-scheduler-text">{{ $aiRuntimeSchedulerRunning ? 'running' : 'not running' }}</span></p>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('admin.behavior-ai.runtime.start') }}">
                            @csrf
                            <button class="rounded bg-ink px-3 py-1.5 text-xs font-semibold text-white">Start AI Runtime</button>
                        </form>
                        <a href="{{ route('admin.behavior-ai.index') }}" class="rounded border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-700">Open AI Control Center</a>
                    </div>
                </div>
                <div id="runtime-alert-agent-card" class="@if($agentBackendRunning) hidden @endif rounded-xl border border-amber-200 bg-white p-4">
                    <p class="text-sm font-medium text-slate-900">Agent backend server is not running</p>
                    <p class="mt-1 text-xs text-slate-600">Policy/install actions that depend on it may fail.</p>
                    <p id="global-agent-backend-meta" class="mt-2 text-[11px] font-mono text-slate-500">{{ $agentBackendHost }}:{{ $agentBackendPort }}@if($agentBackendError) | {{ $agentBackendError }}@endif</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('admin.agent.backend.start') }}">
                            @csrf
                            <button class="rounded bg-ink px-3 py-1.5 text-xs font-semibold text-white">Start Agent Backend</button>
                        </form>
                        <a href="{{ route('admin.agent') }}" class="rounded border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-700">Open Agent Delivery</a>
                    </div>
                </div>
                <div class="rounded-xl border border-amber-200 bg-white/80 px-3 py-2 text-[11px] text-slate-600">
                    This check refreshes automatically every 10 seconds.
                </div>
            </div>
        </div>
    </div>
<div id="kill-switch-modal" class="modal-backdrop hidden fixed inset-0 z-[115] px-4">
    <div class="flex min-h-full items-center justify-center">
        <div class="w-full max-w-md rounded-2xl border border-rose-200 bg-white">
            <div class="border-b border-slate-200 px-5 py-4">
                <h3 id="kill-switch-modal-title" class="text-base font-semibold text-slate-900">Engage Emergency Kill Switch</h3>
                <p class="mt-1 text-xs text-slate-600">This action requires admin password confirmation.</p>
            </div>
            <form id="kill-switch-modal-form" method="POST" action="{{ route('admin.ops.kill-switch') }}">
                @csrf
                <div class="space-y-3 px-5 py-4">
                    <div id="kill-switch-modal-warning" class="brand-modal-note rounded-lg px-3 py-2 text-xs">
                        Pause all new command dispatch from the control plane until you explicitly resume it.
                    </div>
                    <input type="hidden" name="enabled" id="kill-switch-enabled" value="">
                    <div>
                        <label for="kill-switch-password" class="mb-1 block text-xs font-medium text-slate-600">Enter your admin password to confirm:</label>
                        <input id="kill-switch-password" name="admin_password" type="password" class="brand-modal-input w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900" autocomplete="current-password" />
                    </div>
                    <p id="kill-switch-modal-error" class="brand-modal-note hidden rounded-lg px-3 py-2 text-xs">Password is required.</p>
                </div>
                <div class="flex items-center justify-end gap-2 border-t border-slate-200 px-5 py-4">
                    <button id="kill-switch-cancel" type="button" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-700">Cancel</button>
                    <button id="kill-switch-confirm" type="submit" class="brand-modal-action rounded-lg px-3 py-2 text-xs font-medium">Pause Dispatch</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div id="confirm-modal" class="modal-backdrop fixed inset-0 z-[100] hidden items-center justify-center p-4">
    <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white shadow-2xl">
        <div class="border-b border-slate-200 px-5 py-4">
            <p class="text-sm uppercase tracking-wide text-slate-500">Please Confirm</p>
            <h3 class="text-lg font-semibold text-ink">Action Confirmation</h3>
        </div>
        <div class="px-5 py-4">
            <p id="confirm-modal-message" class="text-sm text-slate-700"></p>
        </div>
        <div class="flex items-center justify-end gap-2 border-t border-slate-200 px-5 py-4">
            <button id="confirm-modal-cancel" type="button" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm text-slate-700">Cancel</button>
            <button id="confirm-modal-ok" type="button" class="rounded-lg bg-rose-600 px-4 py-2 text-sm text-white">Confirm</button>
        </div>
    </div>
</div>
<script>
    (function () {
        window.syncAdminModalState = function () {
            const modalIds = ['runtime-alert-popup', 'kill-switch-modal', 'confirm-modal'];
            const hasOpenModal = modalIds.some(function (id) {
                const el = document.getElementById(id);
                return !!el && !el.classList.contains('hidden');
            });

            document.body.classList.toggle('ui-modal-open', hasOpenModal);
        };

        window.syncAdminModalState();
    })();
</script>
<script>
    (function () {
        const popup = document.getElementById('runtime-alert-popup');
        const closeBtn = document.getElementById('runtime-alert-close');
        const aiCard = document.getElementById('runtime-alert-ai-card');
        const agentCard = document.getElementById('runtime-alert-agent-card');
        const queueText = document.getElementById('global-runtime-queue-text');
        const schedulerText = document.getElementById('global-runtime-scheduler-text');
        const agentMeta = document.getElementById('global-agent-backend-meta');
        const agentStatusLine = document.getElementById('agent-backend-status-line');
        const agentEndpointLine = document.getElementById('agent-backend-endpoint-line');
        const behaviorQueueLine = document.getElementById('behavior-runtime-queue-line');
        const behaviorSchedulerLine = document.getElementById('behavior-runtime-scheduler-line');
        const runtimeStatusUrl = @json(route('admin.behavior-ai.runtime.status'));
        const backendStatusUrl = @json(route('admin.agent.backend.status'));

        let popupDismissed = false;

        function setBadge(el, running) {
            if (!el) return;
            el.className = 'rounded-full px-2 py-0.5 ' + (running ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700');
            el.textContent = running ? 'running' : 'not running';
        }

        function syncPopupVisibility() {
            if (!popup) return;
            const aiOffline = aiCard && !aiCard.classList.contains('hidden');
            const agentOffline = agentCard && !agentCard.classList.contains('hidden');
            const hasAlert = aiOffline || agentOffline;
            popup.classList.toggle('hidden', !hasAlert || popupDismissed);
            if (!hasAlert) {
                popupDismissed = false;
            }
            window.syncAdminModalState?.();
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                popupDismissed = true;
                popup?.classList.add('hidden');
            });
        }

        async function pollRuntimeStatus() {
            try {
                const res = await fetch(runtimeStatusUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                if (!res.ok) return;
                const data = await res.json();
                const queueRunning = !!data.queue_running;
                const schedulerRunning = !!data.scheduler_running;

                if (queueText) queueText.textContent = queueRunning ? 'running' : 'not running';
                if (schedulerText) schedulerText.textContent = schedulerRunning ? 'running' : 'not running';
                setBadge(behaviorQueueLine, queueRunning);
                setBadge(behaviorSchedulerLine, schedulerRunning);

                if (aiCard) {
                    aiCard.classList.toggle('hidden', queueRunning && schedulerRunning);
                }

                syncPopupVisibility();
            } catch (e) {
                // Ignore transient polling failures.
            }
        }

        async function pollAgentStatus() {
            try {
                const res = await fetch(backendStatusUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                if (!res.ok) return;
                const data = await res.json();
                const running = !!data.running;
                const meta = `${data.host}:${data.port}${data.error ? ` | ${data.error}` : ''}`;

                if (agentMeta) agentMeta.textContent = meta;
                if (agentEndpointLine) agentEndpointLine.textContent = `${data.host}:${data.port}`;
                if (agentStatusLine) {
                    agentStatusLine.innerHTML = running
                        ? 'Status: <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700">running</span>'
                        : 'Status: <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-1 text-xs font-medium text-amber-700">not running</span>';
                }

                if (agentCard) {
                    agentCard.classList.toggle('hidden', running);
                }

                syncPopupVisibility();
            } catch (e) {
                // Ignore transient polling failures.
            }
        }

        syncPopupVisibility();
        pollRuntimeStatus();
        pollAgentStatus();
        setInterval(pollRuntimeStatus, 10000);
        setInterval(pollAgentStatus, 10000);
    })();
</script>
<script>
    (function () {
        const openBtn = document.getElementById('mobile-nav-open');
        const overlay = document.getElementById('mobile-nav-overlay');
        if (!openBtn || !overlay) return;

        const closeBtns = Array.from(overlay.querySelectorAll('[data-mobile-nav-close]'));
        const navLinks = Array.from(overlay.querySelectorAll('nav a'));
        const scrollPanel = document.getElementById('mobile-nav-scroll');
        const desktopMq = window.matchMedia('(min-width: 1024px)');

        function setMobileNav(open) {
            overlay.classList.toggle('hidden', !open);
            document.body.style.overflow = open ? 'hidden' : '';
            openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open && scrollPanel) {
                scrollPanel.scrollTop = 0;
            }
        }

        openBtn.addEventListener('click', function () {
            setMobileNav(true);
        });

        closeBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                setMobileNav(false);
            });
        });

        navLinks.forEach(function (link) {
            link.addEventListener('click', function () {
                setMobileNav(false);
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                setMobileNav(false);
            }
        });

        if (typeof desktopMq.addEventListener === 'function') {
            desktopMq.addEventListener('change', function (event) {
                if (event.matches) {
                    setMobileNav(false);
                }
            });
        } else if (typeof desktopMq.addListener === 'function') {
            desktopMq.addListener(function (event) {
                if (event.matches) {
                    setMobileNav(false);
                }
            });
        }
    })();
</script>
<script>
    (function () {
        const root = document.getElementById('topbar-profile-root');
        const btn = document.getElementById('topbar-profile-btn');
        const menu = document.getElementById('topbar-profile-menu');
        if (!root || !btn || !menu) return;

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            menu.classList.toggle('hidden');
        });

        document.addEventListener('click', function (e) {
            if (!root.contains(e.target)) {
                menu.classList.add('hidden');
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                menu.classList.add('hidden');
            }
        });
    })();
</script>
<script>
    (function () {
        const triggers = Array.from(document.querySelectorAll('[data-kill-switch-trigger]'));
        const modal = document.getElementById('kill-switch-modal');
        const titleNode = document.getElementById('kill-switch-modal-title');
        const warningNode = document.getElementById('kill-switch-modal-warning');
        const enabledField = document.getElementById('kill-switch-enabled');
        const passwordInput = document.getElementById('kill-switch-password');
        const errorNode = document.getElementById('kill-switch-modal-error');
        const cancelBtn = document.getElementById('kill-switch-cancel');
        const confirmBtn = document.getElementById('kill-switch-confirm');
        const form = document.getElementById('kill-switch-modal-form');
        const initialEnabled = @json(old('enabled'));
        const initialError = @json($errors->first('kill_switch'));

        if (!modal || !titleNode || !warningNode || !enabledField || !passwordInput || !errorNode || !cancelBtn || !confirmBtn || !form || triggers.length === 0) {
            return;
        }

        function closeModal() {
            modal.classList.add('hidden');
            enabledField.value = '';
            passwordInput.value = '';
            errorNode.textContent = 'Password is required.';
            errorNode.classList.add('hidden');
            window.syncAdminModalState?.();
        }

        function openModal(options) {
            const enableSwitch = !!options.enableSwitch;
            titleNode.textContent = options.title || (enableSwitch ? 'Engage Emergency Kill Switch' : 'Restore Command Dispatch');
            warningNode.textContent = options.description || (enableSwitch
                ? 'Immediately stop all new command dispatch from the control plane until an administrator explicitly restores it.'
                : 'Release the kill switch and allow new command dispatch to continue from the control plane.');
            warningNode.className = 'brand-modal-note rounded-lg px-3 py-2 text-xs';
            enabledField.value = enableSwitch ? '1' : '0';
            confirmBtn.textContent = options.confirmLabel || (enableSwitch ? 'Engage Kill Switch' : 'Restore Dispatch');
            confirmBtn.className = 'brand-modal-action rounded-lg px-3 py-2 text-xs font-medium';
            errorNode.classList.add('hidden');
            modal.classList.remove('hidden');
            passwordInput.focus();
            window.syncAdminModalState?.();
        }

        triggers.forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                openModal({
                    enableSwitch: trigger.dataset.killSwitchEnabled === '1',
                    title: trigger.dataset.killSwitchTitle || '',
                    description: trigger.dataset.killSwitchDescription || '',
                    confirmLabel: trigger.dataset.killSwitchConfirm || '',
                });
            });
        });

        cancelBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
                closeModal();
            }
        });
        form.addEventListener('submit', function (event) {
            if (passwordInput.value.trim() !== '') {
                return;
            }
            event.preventDefault();
            errorNode.textContent = 'Password is required.';
            errorNode.classList.remove('hidden');
            passwordInput.focus();
        });

        if (initialError) {
            openModal({
                enableSwitch: String(initialEnabled) === '1',
                title: String(initialEnabled) === '1' ? 'Engage Emergency Kill Switch' : 'Restore Command Dispatch',
                description: String(initialEnabled) === '1'
                    ? 'Immediately stop all new command dispatch from the control plane until an administrator explicitly restores it.'
                    : 'Release the kill switch and allow new command dispatch to continue from the control plane.',
                confirmLabel: String(initialEnabled) === '1' ? 'Engage Kill Switch' : 'Restore Dispatch',
            });
            errorNode.textContent = initialError;
            errorNode.classList.remove('hidden');
        }
    })();
</script>
<script>
    (function () {
        const modal = document.getElementById('confirm-modal');
        const msg = document.getElementById('confirm-modal-message');
        const okBtn = document.getElementById('confirm-modal-ok');
        const cancelBtn = document.getElementById('confirm-modal-cancel');
        if (!modal || !msg || !okBtn || !cancelBtn) return;

        let pendingForm = null;

        function extractConfirmMessage(form) {
            if (form.dataset.confirmMessage && form.dataset.confirmMessage.trim() !== '') {
                return form.dataset.confirmMessage;
            }
            const inline = form.getAttribute('onsubmit') || '';
            const match = inline.match(/confirm\((['"])([\s\S]*?)\1\)/);
            if (!match || !match[2]) {
                return '';
            }
            const text = match[2];
            form.dataset.confirmMessage = text;
            form.removeAttribute('onsubmit');
            return text;
        }

        function closeModal() {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            pendingForm = null;
            window.syncAdminModalState?.();
        }

        function openModal(message, form) {
            msg.textContent = message || 'Are you sure?';
            pendingForm = form;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            okBtn.focus();
            window.syncAdminModalState?.();
        }

        cancelBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                closeModal();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
                closeModal();
            }
        });
        okBtn.addEventListener('click', function () {
            if (!pendingForm) return;
            pendingForm.dataset.confirmBypass = '1';
            const form = pendingForm;
            closeModal();
            form.submit();
        });

        document.addEventListener('submit', function (e) {
            const form = e.target;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            if (form.dataset.confirmBypass === '1') {
                form.dataset.confirmBypass = '0';
                return;
            }

            const message = extractConfirmMessage(form);
            if (!message) {
                return;
            }

            e.preventDefault();
            openModal(message, form);
        }, true);
    })();
</script>
<script>
    (function () {
        const iconMap = {
            'Overview': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M3 10.5L12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg>',
            'Devices': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="4" y="3" width="16" height="12" rx="2"/><path d="M8 21h8M12 15v6"/></svg>',
            'Groups': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M16 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M8 12a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"/><path d="M2.5 20a5.5 5.5 0 0 1 11 0"/><path d="M13 20a5 5 0 0 1 8.5-3.5"/></svg>',
            'Software Packages': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3 4 7l8 4 8-4-8-4Z"/><path d="M4 7v10l8 4 8-4V7"/></svg>',
            'Application Management': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="3" y="4" width="18" height="6" rx="2"/><rect x="3" y="14" width="18" height="6" rx="2"/></svg>',
            'Policies': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3v18"/><path d="M6 7h12"/><path d="M6 17h12"/><path d="M8.5 7a3.5 3.5 0 0 1 0 7"/><path d="M15.5 17a3.5 3.5 0 0 0 0-7"/></svg>',
            'Policy Catalog': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M5 4h11a3 3 0 0 1 3 3v13H8a3 3 0 0 0-3 3V4Z"/><path d="M8 8h7M8 12h7M8 16h5"/></svg>',
            'Categories': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M3 8h18"/><path d="M3 12h18"/><path d="M3 16h18"/></svg>',
            'Policy Categories': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M3 8h18"/><path d="M3 12h18"/><path d="M3 16h18"/></svg>',
            'Jobs': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>',
            'Behavior Alerts': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3 4 7v6c0 5 3.5 7.8 8 9 4.5-1.2 8-4 8-9V7l-8-4Z"/><path d="M12 8v5"/><circle cx="12" cy="16.5" r="0.9"/></svg>',
            'Agent Delivery': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 2 3 7l9 5 9-5-9-5Z"/><path d="M3 17l9 5 9-5"/><path d="M3 12l9 5 9-5"/></svg>',
            'IP Deployment': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/></svg>',
            'Settings': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M10.3 3h3.4l.6 2.2a7.8 7.8 0 0 1 1.8.8l2-1.1 2.4 2.4-1.1 2a7.8 7.8 0 0 1 .8 1.8l2.2.6v3.4l-2.2.6a7.8 7.8 0 0 1-.8 1.8l1.1 2-2.4 2.4-2-1.1a7.8 7.8 0 0 1-1.8.8l-.6 2.2h-3.4l-.6-2.2a7.8 7.8 0 0 1-1.8-.8l-2 1.1-2.4-2.4 1.1-2a7.8 7.8 0 0 1-.8-1.8L3 13.7v-3.4l2.2-.6a7.8 7.8 0 0 1 .8-1.8l-1.1-2 2.4-2.4 2 1.1a7.8 7.8 0 0 1 1.8-.8l.6-2.2Z"/><circle cx="12" cy="12" r="3"/></svg>',
            'General': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M10.3 3h3.4l.6 2.2a7.8 7.8 0 0 1 1.8.8l2-1.1 2.4 2.4-1.1 2a7.8 7.8 0 0 1 .8 1.8l2.2.6v3.4l-2.2.6a7.8 7.8 0 0 1-.8 1.8l1.1 2-2.4 2.4-2-1.1a7.8 7.8 0 0 1-1.8.8l-.6 2.2h-3.4l-.6-2.2a7.8 7.8 0 0 1-1.8-.8l-2 1.1-2.4-2.4 1.1-2a7.8 7.8 0 0 1-.8-1.8L3 13.7v-3.4l2.2-.6a7.8 7.8 0 0 1 .8-1.8l-1.1-2 2.4-2.4 2 1.1a7.8 7.8 0 0 1 1.8-.8l.6-2.2Z"/><circle cx="12" cy="12" r="3"/></svg>',
            'Branding': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3a9 9 0 0 0-9 9c0 4 3 7 7 7h1v2h2v-2h1a7 7 0 0 0 0-14h-2z"/><circle cx="8" cy="10" r="1"/><circle cx="12" cy="8" r="1"/><circle cx="15" cy="11" r="1"/></svg>',
            'Enroll Devices': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="3" y="4" width="18" height="14" rx="2"/><path d="M7 20h10"/><path d="m9 11 2 2 4-4"/></svg>',
            'Access': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>',
            'Access Control': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>',
            'Docs': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M7 3h7l5 5v13H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M14 3v5h5"/></svg>',
            'Audit Logs': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/><path d="M11 8v3l2 2"/></svg>',
            'Policy Center': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3 4 7v6c0 5 3.5 7.8 8 9 4.5-1.2 8-4 8-9V7l-8-4Z"/></svg>',
            'Autonomous Remediation': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3 5 6v6c0 4.5 3 7.7 7 9 4-1.3 7-4 7-9V6l-7-3Z"/><path d="M8 12h8M12 8v8"/></svg>',
            'Deployment Center': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 2v20"/><path d="M5 7h14"/><path d="M7 12h10"/><path d="M9 17h6"/></svg>'
        };

        function cleanText(el) {
            return (el.textContent || '').replace(/\s+/g, ' ').trim();
        }

        function addIcon(el, iconHtml) {
            if (!el || !iconHtml || el.dataset.iconized === '1') return;
            const text = cleanText(el);
            el.textContent = '';
            const iconSpan = document.createElement('span');
            iconSpan.setAttribute('aria-hidden', 'true');
            iconSpan.className = 'text-current';
            iconSpan.innerHTML = iconHtml;
            const textSpan = document.createElement('span');
            textSpan.textContent = text;
            if (el.classList.contains('text-center')) {
                el.classList.add('inline-flex', 'items-center', 'justify-center', 'gap-1.5');
            } else {
                el.classList.add('flex', 'items-center', 'gap-2');
            }
            el.appendChild(iconSpan);
            el.appendChild(textSpan);
            el.dataset.iconized = '1';
        }

        document.querySelectorAll('aside nav a, .lg\\:hidden nav a').forEach(function (a) {
            const txt = cleanText(a);
            if (iconMap[txt]) addIcon(a, iconMap[txt]);
        });

        document.querySelectorAll('aside nav summary, .lg\\:hidden nav summary').forEach(function (s) {
            const raw = cleanText(s).replace(/[v+-]$/, '').trim();
            for (const [label, iconHtml] of Object.entries(iconMap)) {
                if (raw.startsWith(label)) {
                    if (s.dataset.iconized === '1') break;
                    const arrow = document.createElement('span');
                    arrow.className = 'expand-indicator text-xs';
                    const left = document.createElement('span');
                    left.className = 'inline-flex items-center gap-2';
                    const iconSpan = document.createElement('span');
                    iconSpan.setAttribute('aria-hidden', 'true');
                    iconSpan.className = 'text-current';
                    iconSpan.innerHTML = iconHtml;
                    const textSpan = document.createElement('span');
                    textSpan.textContent = label;
                    left.appendChild(iconSpan);
                    left.appendChild(textSpan);
                    s.textContent = '';
                    if (!s.classList.contains('flex')) {
                        s.classList.add('flex', 'items-center', 'justify-between');
                    }
                    s.appendChild(left);
                    s.appendChild(arrow);
                    s.dataset.iconized = '1';
                    break;
                }
            }
        });
    })();
</script>
</body>
</html>
