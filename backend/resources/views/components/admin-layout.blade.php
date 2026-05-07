<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
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
    $currentScheme = strtolower((string) request()->getScheme());
    $currentHost = strtolower((string) request()->getHost());
    $normalizeLocalAssetScheme = function (?string $url) use ($currentScheme, $currentHost): string {
        $url = trim((string) $url);
        if ($url === '' || preg_match('/^https?:\/\//i', $url) !== 1) {
            return $url;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        if ($host === '' || $host !== $currentHost || $scheme === $currentScheme) {
            return $url;
        }

        $userInfo = '';
        if (isset($parts['user'])) {
            $userInfo = (string) $parts['user'];
            if (isset($parts['pass'])) {
                $userInfo .= ':'.(string) $parts['pass'];
            }
            $userInfo .= '@';
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $currentScheme.'://'.$userInfo.$host.$port.$path.$query.$fragment;
    };
    $brandLogo = $normalizeLocalAssetScheme($brandLogo);
    $brandFavicon = $normalizeLocalAssetScheme($brandFavicon);
    $endpointIntelligenceSetting = \App\Models\ControlPlaneSetting::query()->find('endpoint_intelligence.enabled');
    $endpointIntelligenceEnabled = is_array($endpointIntelligenceSetting?->value ?? null)
        ? (bool) ($endpointIntelligenceSetting->value['value'] ?? true)
        : true;
    $topbarUser = auth()->user();
    $topbarUserName = trim((string) ($topbarUser?->name ?? 'User')) ?: 'User';
    $topbarInitial = strtoupper(substr($topbarUserName, 0, 1));
    $topbarIsSuperAdmin = false;
    $topbarCanManageSaasTenants = false;
    $standaloneMode = (bool) config('dms.standalone_mode', true);
    if ($topbarUser) {
        $topbarIsSuperAdmin = \Illuminate\Support\Facades\DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $topbarUser->id)
            ->where('roles.slug', 'super-admin')
            ->exists();

        if ($topbarIsSuperAdmin && ! $standaloneMode) {
            if (empty($topbarUser->tenant_id)) {
                $topbarCanManageSaasTenants = true;
            } else {
                $hasPlatformSuperAdmin = \Illuminate\Support\Facades\DB::table('users')
                    ->join('role_user', 'role_user.user_id', '=', 'users.id')
                    ->join('roles', 'roles.id', '=', 'role_user.role_id')
                    ->whereNull('users.tenant_id')
                    ->where('roles.slug', 'super-admin')
                    ->exists();
                $topbarCanManageSaasTenants = ! $hasPlatformSuperAdmin;
            }
        }
    }
    $topbarPermissionSlugs = collect();
    if ($topbarUser) {
        $topbarPermissionSlugs = \Illuminate\Support\Facades\DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('role_user.user_id', $topbarUser->id)
            ->pluck('permissions.slug')
            ->map(fn ($slug) => (string) $slug)
            ->unique()
            ->values();
    }
    $topbarCan = function ($permissions) use ($topbarIsSuperAdmin, $topbarPermissionSlugs): bool {
        if ($topbarIsSuperAdmin) {
            return true;
        }

        foreach ((array) $permissions as $permission) {
            if ($topbarPermissionSlugs->contains((string) $permission)) {
                return true;
            }
        }

        return false;
    };
    $navCanManageDevices = $topbarCan(['devices.read', 'devices.write']);
    $navCanWriteDevices = $topbarCan('devices.write');
    $navCanManageGroups = $topbarCan(['groups.read', 'groups.write']);
    $navCanWriteGroups = $topbarCan('groups.write');
    $navCanManagePackages = $topbarCan(['packages.read', 'packages.write']);
    $navCanWritePackages = $topbarCan('packages.write');
    $navCanManagePolicies = $topbarCan(['policies.read', 'policies.write']);
    $navCanWritePolicies = $topbarCan('policies.write');
    $navCanManageJobs = $topbarCan(['jobs.read', 'jobs.write']);
    $navCanAccessAudit = $topbarCan('audit.read');
    $navCanUseAssistant = $topbarCan('assistant.use');
    $navCanReadHealth = $topbarCan('health.read');
    $navCanReadRisk = $topbarCan('risk.read');
    $navCanReadIncidents = $topbarCan('incidents.read');
    $navCanReadRemediation = $topbarCan('remediation.read');
    $navCanApproveRemediation = $topbarCan('remediation.approve');
    $navCanManageAutonomy = $topbarCan('autonomy.manage');
    $navCanReadAutonomous = $topbarCan('autonomous.read');
    $navCanManageAutonomous = $topbarCan('autonomous.manage');
    $navCanApproveAutonomous = $topbarCan('autonomous.approve');
    $navCanExecuteAutonomous = $topbarCan('autonomous.execute');
    $navShowEndpointIntelligence = $endpointIntelligenceEnabled && (
        $navCanReadHealth
        || $navCanReadRisk
        || $navCanReadIncidents
        || $navCanUseAssistant
        || $navCanReadRemediation
        || $navCanApproveRemediation
        || $navCanManageAutonomy
        || $navCanReadAutonomous
        || $navCanManageAutonomous
        || $navCanApproveAutonomous
        || $navCanExecuteAutonomous
    );
    $topbarUserAvatar = null;
    $profileSettingValue = [];
    if ($topbarUser) {
        $profileSetting = \App\Models\ControlPlaneSetting::query()->find('users.profile.'.$topbarUser->id);
        $profileSettingValue = is_array($profileSetting?->value ?? null) ? ($profileSetting->value['value'] ?? []) : [];
        if (is_array($profileSettingValue) && is_string($profileSettingValue['avatar_url'] ?? null)) {
            $topbarUserAvatar = trim((string) $profileSettingValue['avatar_url']) ?: null;
        }
    }
    $supportedLocales = \App\Support\LocaleManager::supported();
    $topbarCurrentLocale = \App\Support\LocaleManager::normalize((string) (session('locale') ?? ($profileSettingValue['locale'] ?? config('app.locale', 'en'))));
    $topbarCurrentLocaleLabel = $supportedLocales[$topbarCurrentLocale] ?? strtoupper($topbarCurrentLocale);
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
    $topbarMetricsTtlSeconds = max(15, min(300, (int) env('ADMIN_LAYOUT_METRICS_TTL_SECONDS', 45)));
    $topbarMetricsTenantScope = (string) (session('active_tenant_id') ?? ($topbarUser?->tenant_id ?? 'platform'));
    $topbarMetricsCachePrefix = 'admin.layout.metrics.'.$topbarMetricsTenantScope.'.';

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
    $securitySettings = collect(\Illuminate\Support\Facades\Cache::remember(
        $topbarMetricsCachePrefix.'security-settings',
        now()->addSeconds($topbarMetricsTtlSeconds),
        function () use ($securitySettingKeys) {
            return \App\Models\ControlPlaneSetting::query()
                ->whereIn('key', $securitySettingKeys)
                ->get(['key', 'value'])
                ->mapWithKeys(function ($row) {
                    $val = is_array($row->value ?? null) ? ($row->value['value'] ?? null) : null;
                    return [$row->key => $val];
                })
                ->all();
        }
    ));
    $securityGet = function (string $key, mixed $default = null) use ($securitySettings) {
        return $securitySettings->has($key) ? $securitySettings->get($key) : $default;
    };

    $securitySignatureBypassEnabled = (bool) $securityGet('security.signature_bypass_enabled', filter_var((string) env('DMS_SIGNATURE_BYPASS', 'false'), FILTER_VALIDATE_BOOL));
    $securityDefaultRequireMfa = !app()->environment(['local', 'testing']);
    $securityAuthRequireMfa = (bool) $securityGet('auth.require_mfa', $securityDefaultRequireMfa);
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
        : 'kill-switch-card kill-switch-card-live';
    $topbarKillSwitchIconClass = $topbarKillSwitchEnabled
        ? 'kill-switch-icon-shell kill-switch-icon-shell-halted'
        : 'kill-switch-icon-shell';
    $topbarKillSwitchIconTone = $topbarKillSwitchEnabled ? 'text-rose-700' : 'text-emerald-700';
    $topbarKillSwitchActionTone = $topbarKillSwitchEnabled ? 'text-rose-700' : 'text-emerald-700';
    $topbarKillSwitchActionChip = $topbarKillSwitchEnabled
        ? 'kill-switch-chip kill-switch-chip-restore'
        : 'kill-switch-chip kill-switch-chip-danger';
    $topbarKillSwitchCardStyle = $topbarKillSwitchEnabled
        ? 'border-color:#fb7185;background-color:#fff1f2;'
        : 'border-color:#86efac;background-color:#f0fdf4;';
    $topbarKillSwitchIconStyle = $topbarKillSwitchEnabled
        ? 'border-color:#fb7185;background-color:#ffe4e6;'
        : 'border-color:#86efac;background-color:#f0fdf4;';
    $topbarKillSwitchChipStyle = $topbarKillSwitchEnabled
        ? 'border-color:#be123c;background-color:#be123c;color:#ffffff;'
        : 'border-color:#16a34a;background-color:#f0fdf4;color:#166534;';
    $topbarKillSwitchStatus = $topbarKillSwitchEnabled ? 'Dispatch Halted' : 'Dispatch Live';
    $topbarKillSwitchCardStatus = $topbarKillSwitchEnabled ? 'Halted' : 'Active';
    $topbarKillSwitchActionLabel = $topbarKillSwitchEnabled ? 'Restore Dispatch' : 'Engage Kill Switch';
    $topbarKillSwitchSummary = $topbarKillSwitchEnabled
        ? 'Dispatch is paused.'
        : 'Halts new command dispatch.';
    $topbarKillSwitchModalTitle = $topbarKillSwitchEnabled ? 'Restore Command Dispatch' : 'Engage Emergency Kill Switch';
    $topbarKillSwitchModalDescription = $topbarKillSwitchEnabled
        ? 'Release the kill switch and allow new command dispatch to continue from the control plane.'
        : 'Immediately stop all new command dispatch from the control plane until an administrator explicitly restores it.';
    $topbarKillSwitchConfirmLabel = $topbarKillSwitchEnabled ? 'Restore Dispatch' : 'Engage Kill Switch';
    $topbarKillSwitchConfirmPhrase = $topbarKillSwitchEnabled ? 'RESTORE DISPATCH' : 'PAUSE DISPATCH';
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
    $securityStaleActiveRuns = (int) \Illuminate\Support\Facades\Cache::remember(
        $topbarMetricsCachePrefix.'stale-active-runs',
        now()->addSeconds($topbarMetricsTtlSeconds),
        fn () => \App\Models\JobRun::query()
            ->whereIn('status', ['pending', 'acked', 'running'])
            ->where('updated_at', '<', now()->subMinutes(30))
            ->count()
    );
    $securityRecentFailedRuns = (int) \Illuminate\Support\Facades\Cache::remember(
        $topbarMetricsCachePrefix.'recent-failed-runs',
        now()->addSeconds($topbarMetricsTtlSeconds),
        fn () => \App\Models\JobRun::query()
            ->whereIn('status', ['failed', 'non_compliant'])
            ->where('updated_at', '>=', now()->subHours(24))
            ->count()
    );

    $securityControls = [
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
    $aiAccuracyStats = \Illuminate\Support\Facades\Cache::remember(
        $topbarMetricsCachePrefix.'ai-accuracy-'.$aiAccuracyWindowDays.'d',
        now()->addSeconds($topbarMetricsTtlSeconds),
        function () use ($aiAccuracyWindowDays) {
            $reviewed = \App\Models\BehaviorPolicyFeedback::query()
                ->whereIn('decision', ['approved', 'edited', 'rejected', 'false_positive', 'false_negative'])
                ->where('created_at', '>=', now()->subDays($aiAccuracyWindowDays))
                ->count();
            $correct = \App\Models\BehaviorPolicyFeedback::query()
                ->whereIn('decision', ['approved', 'edited'])
                ->where('created_at', '>=', now()->subDays($aiAccuracyWindowDays))
                ->count();

            return ['reviewed' => (int) $reviewed, 'correct' => (int) $correct];
        }
    );
    $aiAccuracyReviewedTotal = (int) ($aiAccuracyStats['reviewed'] ?? 0);
    $aiAccuracyCorrectTotal = (int) ($aiAccuracyStats['correct'] ?? 0);
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

    $runtimeHeartbeatIsFresh = function (string $name, int $maxAgeSeconds = 90): bool {
        $path = storage_path('runtime'.DIRECTORY_SEPARATOR.$name);
        if (!is_file($path)) {
            return false;
        }

        $raw = trim((string) @file_get_contents($path));
        if ($raw === '') {
            return false;
        }

        $timestamp = 0;
        if (is_numeric($raw)) {
            $timestamp = (int) $raw;
        } else {
            $parsed = strtotime($raw);
            $timestamp = $parsed !== false ? (int) $parsed : 0;
        }

        if ($timestamp <= 0) {
            return false;
        }

        return abs(time() - $timestamp) <= $maxAgeSeconds;
    };

    $runtimeStatus = \Illuminate\Support\Facades\Cache::remember(
        $topbarMetricsCachePrefix.'runtime-status',
        now()->addSeconds($topbarMetricsTtlSeconds),
        function () use ($runtimeHeartbeatIsFresh, $processExistsByPattern) {
            $queueRunning = $runtimeHeartbeatIsFresh('queue-heartbeat') || $processExistsByPattern('artisan queue:work');
            $schedulerRunning = $runtimeHeartbeatIsFresh('scheduler-heartbeat') || $processExistsByPattern('artisan schedule:work');

            $agentBackendWorkdir = trim((string) env('AGENT_BACKEND_WORKDIR', ''));
            if ($agentBackendWorkdir === '') {
                $agentBackendWorkdir = base_path('agent-backend');
            }
            $agentBackendConfigured = is_dir($agentBackendWorkdir);
            $agentBackendHost = (string) env('AGENT_BACKEND_HOST', '127.0.0.1');
            $agentBackendPort = (int) env('AGENT_BACKEND_PORT', 8000);
            $agentBackendRunning = false;
            $agentBackendError = null;

            if ($agentBackendConfigured) {
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
            }

            $runtimeRunning = $queueRunning && $schedulerRunning;
            return [
                'aiRuntimeQueueRunning' => $queueRunning,
                'aiRuntimeSchedulerRunning' => $schedulerRunning,
                'aiRuntimeRunning' => $runtimeRunning,
                'agentBackendConfigured' => $agentBackendConfigured,
                'agentBackendHost' => $agentBackendHost,
                'agentBackendPort' => $agentBackendPort,
                'agentBackendRunning' => $agentBackendRunning,
                'agentBackendError' => $agentBackendError,
                'showRuntimePopup' => ! $runtimeRunning || ($agentBackendConfigured && ! $agentBackendRunning),
            ];
        }
    );
    $aiRuntimeQueueRunning = (bool) ($runtimeStatus['aiRuntimeQueueRunning'] ?? false);
    $aiRuntimeSchedulerRunning = (bool) ($runtimeStatus['aiRuntimeSchedulerRunning'] ?? false);
    $aiRuntimeRunning = (bool) ($runtimeStatus['aiRuntimeRunning'] ?? false);
    $agentBackendConfigured = (bool) ($runtimeStatus['agentBackendConfigured'] ?? false);
    $agentBackendHost = (string) ($runtimeStatus['agentBackendHost'] ?? '127.0.0.1');
    $agentBackendPort = (int) ($runtimeStatus['agentBackendPort'] ?? 8000);
    $agentBackendRunning = (bool) ($runtimeStatus['agentBackendRunning'] ?? false);
    $agentBackendError = is_string($runtimeStatus['agentBackendError'] ?? null) ? $runtimeStatus['agentBackendError'] : null;
    $showRuntimePopup = (bool) ($runtimeStatus['showRuntimePopup'] ?? false);
@endphp
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ $title ?? $brandName }}</title>
    @if($brandFavicon !== '')
        <link rel="icon" type="image/png" href="{{ $brandFavicon }}">
    @endif
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
</head>
<body
    class="min-h-screen text-ink admin-layout-shell"
    style="
        --brand-primary: {{ $brandPrimary }};
        --brand-primary-soft: {{ $brandPrimary }}1A;
        --brand-primary-soft-2: {{ $brandPrimary }}26;
        --brand-primary-border: {{ $brandPrimary }}66;
        --brand-accent: {{ $brandAccent }};
        --brand-accent-soft: {{ $brandAccent }}1A;
        --brand-accent-soft-2: {{ $brandAccent }}26;
        --brand-accent-border: {{ $brandAccent }}66;
        --brand-radius-base: {{ $brandRadiusPx }}px;
        --brand-radius-sm: max(2px, calc(var(--brand-radius-base) - 4px));
        --brand-radius-md: max(4px, calc(var(--brand-radius-base) - 2px));
        --brand-radius-lg: var(--brand-radius-base);
        --brand-radius-xl: calc(var(--brand-radius-base) + 2px);
        --brand-radius-2xl: calc(var(--brand-radius-base) + 4px);
        --brand-radius-3xl: calc(var(--brand-radius-base) + 8px);
        --brand-background: {{ $brandBackground }};
        --brand-background-light: {{ $brandBackground }};
        background: var(--theme-page, var(--brand-background-light));
    "
>
<div id="admin-shell" class="flex min-h-screen">
    <aside class="w-72 hidden lg:flex lg:flex-col border-r border-slate-200/60 glass" style="--sidebar-tint-light: {{ $brandSidebarTint }}CC; background: var(--theme-sidebar, var(--sidebar-tint-light));">
        <div class="px-6 py-4 border-b border-slate-200/60">
            <div class="flex items-center justify-center">
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
            @if($navCanWriteDevices)
                <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.enroll-devices*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.enroll-devices') }}">Enroll Devices</a>
            @endif
            <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.dashboard') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.dashboard') }}">Overview</a>
            @if($navCanManageDevices)
                <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.devices') || request()->routeIs('admin.devices.show') || request()->routeIs('admin.devices.live') || request()->routeIs('admin.devices.update') || request()->routeIs('admin.devices.delete') || request()->routeIs('admin.devices.reenroll') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.devices') }}">Devices</a>
                <details class="pt-2 group" {{ request()->routeIs('admin.assets*') ? 'open' : '' }}>
                    <summary class="list-none cursor-pointer rounded-lg px-3 py-1.5 flex items-center justify-between {{ request()->routeIs('admin.assets*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}">
                        <span>Asset Management</span>
                        <span class="expand-indicator text-xs"></span>
                    </summary>
                    <div class="mt-3 pl-2 space-y-2">
                        <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.assets') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.assets') }}">Asset Overview</a>
                        <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.assets.hardware') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.assets.hardware') }}">Hardware Inventory</a>
                        <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.assets.software') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.assets.software') }}">Software Inventory</a>
                        <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.assets.clients') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.assets.clients') }}">Client Management</a>
                    </div>
                </details>
            @endif
            @if($navCanManageGroups)
                <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.groups*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.groups') }}">Groups</a>
            @endif
            @if($navCanManagePackages)
                <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.packages*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.packages') }}">Software Packages</a>
            @endif

            @if($navCanManagePolicies)
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
            @endif
            @if($navCanManageJobs)
                <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.jobs*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.jobs') }}">Jobs</a>
            @endif
            @if($navShowEndpointIntelligence)
                <details class="pt-2 group" {{ request()->routeIs('admin.intelligence.*') ? 'open' : '' }}>
                    <summary class="list-none cursor-pointer rounded-lg px-3 py-1.5 flex items-center justify-between {{ request()->routeIs('admin.intelligence.*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}">
                        <span>Endpoint Intelligence</span>
                        <span class="expand-indicator text-xs"></span>
                    </summary>
                        <div class="mt-3 pl-2 space-y-2">
                            @if($navCanReadHealth)
                            <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.intelligence.health*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.health') }}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M4 12h3l2-5 4 10 2-5h5"/><path d="M12 21c5-3.2 8-6.3 8-10.4A4.6 4.6 0 0 0 12 7a4.6 4.6 0 0 0-8 3.6C4 14.7 7 17.8 12 21Z"/></svg>
                                <span>Fleet Health</span>
                            </a>
                                @endif
                                @if($navCanReadRisk)
                            <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.intelligence.risk*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.risk') }}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M12 3 5 6v5c0 5 3.2 8.5 7 10 3.8-1.5 7-5 7-10V6l-7-3Z"/><path d="M12 8v4"/><circle cx="12" cy="15.5" r="0.9" fill="currentColor" stroke="none"/></svg>
                                <span>Risk Dashboard</span>
                            </a>
                                @endif
                                @if($navCanReadIncidents)
                            <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.intelligence.incidents*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.incidents') }}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="7" r="2.5"/><circle cx="12" cy="18" r="2.5"/><path d="M8.2 7.2 15.6 17"/><path d="M15.7 8.8 12.9 15.6"/></svg>
                                <span>Incidents</span>
                            </a>
                                @endif
                                @if($navCanUseAssistant)
                            <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.intelligence.assistant*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.assistant') }}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M12 3 9.9 8.4 4 10.5l5.9 2.1L12 18l2.1-5.4 5.9-2.1-5.9-2.1L12 3Z"/><path d="M5 3v3"/><path d="M19 18v3"/><path d="M3 5h3"/><path d="M18 19h3"/></svg>
                                <span>AI Assistant</span>
                            </a>
                                @endif
                                @if($navCanReadRemediation)
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.intelligence.remediation*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.remediation') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="m14.5 5.5 4 4"/><path d="M6.8 17.2 17 7a2.8 2.8 0 1 0-4-4L2.8 13.2a2 2 0 0 0-.5 1L2 20l5.8-.3a2 2 0 0 0 1-.5Z"/><path d="m12 8 4 4"/></svg>
                                        <span>Remediation</span>
                                    </a>
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.intelligence.actions*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.actions') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M3 12a9 9 0 1 0 2.6-6.4"/><path d="M3 4v5h5"/><path d="M12 7v5l3 2"/></svg>
                                        <span>Action History</span>
                                    </a>
                                @endif
                                @if($navCanApproveRemediation)
                            <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.intelligence.approvals*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.approvals') }}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M12 3 5 6v6c0 4.7 3 7.9 7 9 4-1.1 7-4.3 7-9V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></svg>
                                <span>Approvals</span>
                            </a>
                                @endif
                                @if($navCanManageAutonomy)
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.intelligence.autonomy*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.autonomy') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M4 7h16"/><path d="M4 17h16"/><path d="M7 7v10"/><path d="M17 7v10"/><circle cx="7" cy="11" r="2.5"/><circle cx="17" cy="13" r="2.5"/></svg>
                                        <span>Autonomy</span>
                                    </a>
                                @endif
                                @if($navCanReadAutonomous)
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.intelligence.autonomous.decisions*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.autonomous.decisions') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M12 3 5 6v6c0 4.5 3 7.7 7 9 4-1.3 7-4 7-9V6l-7-3Z"/><path d="M8 12h8M12 8v8"/></svg>
                                        <span>Autonomous Decisions</span>
                                    </a>
                                @endif
                                @if($navCanManageAutonomous)
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.intelligence.autonomous.policies*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.autonomous.policies') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M4 7h16"/><path d="M4 17h16"/><path d="M7 7v10"/><path d="M17 7v10"/><circle cx="7" cy="11" r="2.5"/><circle cx="17" cy="13" r="2.5"/></svg>
                                        <span>Autonomous Policies</span>
                                    </a>
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.intelligence.autonomous.mappings*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.autonomous.mappings') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h10"/><circle cx="17" cy="18" r="2"/></svg>
                                        <span>Risk Mappings</span>
                                    </a>
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.intelligence.autonomous.catalog*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.autonomous.catalog') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M5 4h11a3 3 0 0 1 3 3v13H8a3 3 0 0 0-3 3V4Z"/><path d="M8 8h7M8 12h7M8 16h5"/></svg>
                                        <span>Action Catalog</span>
                                    </a>
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.intelligence.autonomous.simulate*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.autonomous.simulate') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M8 8a4 4 0 1 1 5.7 3.6L12 13l1.7 1.4A4 4 0 1 1 8 16"/><path d="M8 8h8"/><path d="M8 16h8"/></svg>
                                        <span>Simulation</span>
                                    </a>
                                @endif
                                @if($navCanReadHealth)
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.intelligence.tuning*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.tuning') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M4 6h7"/><path d="M13 6h7"/><path d="M4 18h11"/><path d="M17 18h3"/><path d="M9 3v6"/><path d="M15 15v6"/><circle cx="12" cy="6" r="1.8"/><circle cx="16" cy="18" r="1.8"/></svg>
                                        <span>Tuning</span>
                                    </a>
                                @endif
                            </div>
                        </details>
            @endif
            <details class="pt-2 group" {{ request()->routeIs('admin.agent*') ? 'open' : '' }}>
                <summary class="list-none cursor-pointer rounded-lg px-3 py-1.5 flex items-center justify-between {{ request()->routeIs('admin.agent*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}">
                    <span>Deployment Center</span>
                    <span class="expand-indicator text-xs"></span>
                </summary>
                <div class="mt-3 pl-2 space-y-2">
                    <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.agent*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.agent') }}">Agent Delivery</a>
                </div>
            </details>
            @if($topbarIsSuperAdmin)
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
                <details class="pt-2 group" {{ request()->routeIs('admin.access*') ? 'open' : '' }}>
                    <summary class="list-none cursor-pointer rounded-lg px-3 py-1.5 flex items-center justify-between {{ request()->routeIs('admin.access*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}">
                        <span>Access Control</span>
                        <span class="expand-indicator text-xs"></span>
                    </summary>
                    <div class="mt-3 pl-2 space-y-2">
                        <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.access.users') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.access.users') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9.5" cy="7" r="3"></circle>
                                <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M16 4.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                            <span>Users</span>
                        </a>
                        <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.access.roles') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.access.roles') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true">
                                <path d="M12 3 4 7v6c0 4.5 3 7.7 8 9 5-1.3 8-4.5 8-9V7l-8-4Z"></path>
                                <path d="m9.5 12 1.8 1.8L14.8 10"></path>
                            </svg>
                            <span>Roles</span>
                        </a>
                    </div>
                </details>
            @endif
            @if($topbarCanManageSaasTenants)
                <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.saas.dashboard*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.saas.dashboard') }}">SaaS Dashboard</a>
                <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.saas.tenants*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.saas.tenants') }}">SaaS Tenants</a>
            @endif
            <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.docs*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.docs') }}">Docs</a>
            <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.notes*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }} flex items-center gap-2" href="{{ route('admin.notes') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4" aria-hidden="true">
                    <path d="M7 3h7l5 5v13H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/>
                    <path d="M14 3v5h5"/>
                    <path d="M9 13h6M9 17h6"/>
                </svg>
                <span>Admin Notes</span>
            </a>
            @if($navCanAccessAudit)
                <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.audit*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.audit') }}">Audit Logs</a>
            @endif
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
                @if($topbarIsSuperAdmin)
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
                @endif
                <button
                    type="button"
                    class="topbar-kill-switch-card flex w-[198px] items-center gap-2 rounded-xl border bg-white px-3 py-2 text-left shadow-sm {{ $topbarKillSwitchCardClass }}"
                    style="{{ $topbarKillSwitchCardStyle }}"
                    title="{{ $topbarKillSwitchModalTitle }}"
                    data-kill-switch-trigger="1"
                    data-kill-switch-enabled="{{ $topbarKillSwitchEnabled ? '0' : '1' }}"
                    data-kill-switch-title="{{ $topbarKillSwitchModalTitle }}"
                    data-kill-switch-description="{{ $topbarKillSwitchModalDescription }}"
                    data-kill-switch-confirm="{{ $topbarKillSwitchConfirmLabel }}"
                    data-kill-switch-phrase="{{ $topbarKillSwitchConfirmPhrase }}"
                >
                    <span class="h-8 w-8 rounded-lg flex items-center justify-center {{ $topbarKillSwitchIconClass }}" style="{{ $topbarKillSwitchIconStyle }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4 {{ $topbarKillSwitchIconTone }}">
                            <path d="M12 3v7"></path>
                            <path d="M7.8 6.8a7 7 0 1 0 8.4 0"></path>
                        </svg>
                    </span>
                    <div class="min-w-0 flex-1 leading-tight">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-[10px] uppercase tracking-wide text-slate-500">Kill Switch</p>
                            <span class="{{ $topbarKillSwitchActionChip }}" style="{{ $topbarKillSwitchChipStyle }}">{{ $topbarKillSwitchCardStatus }}</span>
                        </div>
                        <p class="mt-0.5 truncate text-[10px] font-semibold uppercase tracking-[0.08em] {{ $topbarKillSwitchActionTone }}">{{ $topbarKillSwitchActionLabel }}</p>
                    </div>
                </button>
            </div>
            <div class="flex items-center gap-2">
                <form method="POST" action="{{ route('admin.locale.update') }}" class="topbar-locale-form hidden md:flex items-center">
                    @csrf
                    <label class="flex items-center gap-2 rounded-full border border-slate-200 bg-white px-2.5 py-1.5 shadow-sm">
                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-slate-100 text-slate-600" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                                <circle cx="12" cy="12" r="9"></circle>
                                <path d="M3 12h18"></path>
                                <path d="M12 3a15.3 15.3 0 0 1 0 18"></path>
                                <path d="M12 3a15.3 15.3 0 0 0 0 18"></path>
                            </svg>
                        </span>
                        <div class="leading-tight">
                            <p class="text-[10px] uppercase tracking-[0.16em] text-slate-500">{{ __('ui.locale.label') }}</p>
                        </div>
                        <select name="locale" onchange="this.form.submit()" class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-medium text-slate-700 focus:border-slate-300 focus:outline-none">
                            @foreach($supportedLocales as $localeCode => $localeLabel)
                                <option value="{{ $localeCode }}" @selected($topbarCurrentLocale === $localeCode)>{{ $localeLabel }}</option>
                            @endforeach
                        </select>
                    </label>
                </form>
                <x-theme-select
                    id="admin-theme-select"
                    wrapper-class="hidden md:flex items-center"
                    label-class="text-[10px] uppercase tracking-[0.18em] text-slate-500"
                    select-class="theme-select-topbar"
                />
                <nav class="hidden md:flex items-center gap-1.5 px-0 py-0" aria-label="Top shortcuts">
                    @if($navCanWriteDevices)
                        <a href="{{ route('admin.enroll-devices') }}" class="h-9 w-9 rounded-full flex items-center justify-center text-slate-600 hover:text-skyline transition {{ request()->routeIs('admin.enroll-devices*') ? 'text-skyline' : '' }}" title="Enroll Devices" aria-label="Enroll Devices">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5"><rect x="3" y="4" width="18" height="14" rx="2"/><path d="M7 20h10"/><path d="m9 11 2 2 4-4"/></svg>
                        </a>
                    @endif
                    @if($navCanManageDevices)
                        <a href="{{ route('admin.devices') }}" class="h-9 w-9 rounded-full flex items-center justify-center text-slate-600 hover:text-skyline transition {{ request()->routeIs('admin.devices*') ? 'text-skyline' : '' }}" title="Devices" aria-label="Devices">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5"><rect x="4" y="3" width="16" height="12" rx="2"/><path d="M8 21h8M12 15v6"/></svg>
                        </a>
                    @endif
                    @if($navCanManagePolicies)
                        <a href="{{ route('admin.policies') }}" class="h-9 w-9 rounded-full flex items-center justify-center text-slate-600 hover:text-skyline transition {{ request()->routeIs('admin.policies*') || request()->routeIs('admin.catalog*') || request()->routeIs('admin.policy-categories*') ? 'text-skyline' : '' }}" title="Policies" aria-label="Policies">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5"><path d="M12 3v18"/><path d="M6 7h12"/><path d="M6 17h12"/><path d="M8.5 7a3.5 3.5 0 0 1 0 7"/><path d="M15.5 17a3.5 3.5 0 0 0 0-7"/></svg>
                        </a>
                    @endif
                    @if($navCanManagePackages)
                        <a href="{{ route('admin.packages') }}" class="h-9 w-9 rounded-full flex items-center justify-center text-slate-600 hover:text-skyline transition {{ request()->routeIs('admin.packages*') ? 'text-skyline' : '' }}" title="Software Packages" aria-label="Software Packages">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5"><path d="M12 3 4 7l8 4 8-4-8-4Z"/><path d="M4 7v10l8 4 8-4V7"/></svg>
                        </a>
                    @endif
                    @if($navCanManageJobs)
                        <a href="{{ route('admin.jobs') }}" class="h-9 w-9 rounded-full flex items-center justify-center text-slate-600 hover:text-skyline transition {{ request()->routeIs('admin.jobs*') ? 'text-skyline' : '' }}" title="Jobs" aria-label="Jobs">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>
                        </a>
                    @endif
                    <button
                        type="button"
                        class="hidden h-9 w-9 rounded-full items-center justify-center transition md:flex lg:hidden {{ $topbarKillSwitchEnabled ? 'text-rose-700 hover:text-rose-800' : 'text-emerald-700 hover:text-emerald-800' }}"
                        title="Kill Switch: {{ $topbarKillSwitchStatus }}"
                        aria-label="Kill Switch"
                        data-kill-switch-trigger="1"
                        data-kill-switch-enabled="{{ $topbarKillSwitchEnabled ? '0' : '1' }}"
                        data-kill-switch-title="{{ $topbarKillSwitchModalTitle }}"
                        data-kill-switch-description="{{ $topbarKillSwitchModalDescription }}"
                        data-kill-switch-confirm="{{ $topbarKillSwitchConfirmLabel }}"
                        data-kill-switch-phrase="{{ $topbarKillSwitchConfirmPhrase }}"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5"><path d="M12 3v7"/><path d="M7.8 6.8a7 7 0 1 0 8.4 0"/></svg>
                    </button>
                    @if($topbarIsSuperAdmin)
                        <a href="{{ route('admin.settings') }}" class="h-9 w-9 rounded-full flex items-center justify-center text-slate-600 hover:text-skyline transition {{ request()->routeIs('admin.settings*') ? 'text-skyline' : '' }}" title="Settings" aria-label="Settings">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5"><path d="M10.3 3h3.4l.6 2.2a7.8 7.8 0 0 1 1.8.8l2-1.1 2.4 2.4-1.1 2a7.8 7.8 0 0 1 .8 1.8l2.2.6v3.4l-2.2.6a7.8 7.8 0 0 1-.8 1.8l1.1 2-2.4 2.4-2-1.1a7.8 7.8 0 0 1-1.8.8l-.6 2.2h-3.4l-.6-2.2a7.8 7.8 0 0 1-1.8-.8l-2 1.1-2.4-2.4 1.1-2a7.8 7.8 0 0 1-.8-1.8L3 13.7v-3.4l2.2-.6a7.8 7.8 0 0 1 .8-1.8l-1.1-2 2.4-2.4 2 1.1a7.8 7.8 0 0 1 1.8-.8l.6-2.2Z"/><circle cx="12" cy="12" r="3"/></svg>
                        </a>
                    @endif
                </nav>
                <button
                    type="button"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-full md:hidden {{ $topbarKillSwitchEnabled ? 'text-rose-700' : 'text-emerald-700' }}"
                    title="Kill Switch: {{ $topbarKillSwitchStatus }}"
                    aria-label="Kill Switch"
                    data-kill-switch-trigger="1"
                    data-kill-switch-enabled="{{ $topbarKillSwitchEnabled ? '0' : '1' }}"
                    data-kill-switch-title="{{ $topbarKillSwitchModalTitle }}"
                    data-kill-switch-description="{{ $topbarKillSwitchModalDescription }}"
                    data-kill-switch-confirm="{{ $topbarKillSwitchConfirmLabel }}"
                    data-kill-switch-phrase="{{ $topbarKillSwitchConfirmPhrase }}"
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
                    <div id="topbar-profile-menu" class="hidden absolute right-0 mt-2 w-64 rounded-xl border border-slate-200 bg-white shadow-xl z-50">
                        <div class="px-3 py-2 border-b border-slate-200">
                            <p class="text-sm font-medium text-slate-800 truncate">{{ $topbarUserName }}</p>
                            <p class="text-xs text-slate-500 truncate">{{ $topbarUser?->email }}</p>
                        </div>
                    <div class="p-1">
                            <a href="{{ route('admin.profile') }}" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">{{ __('ui.menu.profile') }}</a>
                            @if($topbarIsSuperAdmin)
                                <a href="{{ route('admin.settings') }}" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">{{ __('ui.menu.settings') }}</a>
                                <a href="{{ route('admin.access') }}" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">{{ __('ui.menu.access_control') }}</a>
                                <a href="{{ route('admin.security-hardening') }}" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">{{ __('ui.menu.security_hardening') }}</a>
                            @endif
                            <a href="{{ route('admin.docs') }}" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">{{ __('ui.menu.documentation') }}</a>
                            <a href="{{ route('admin.notes') }}" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">{{ __('ui.menu.admin_notes') }}</a>
                            <form method="POST" action="{{ route('admin.logout') }}">
                                @csrf
                                <button type="submit" class="w-full text-left rounded-lg px-3 py-2 text-sm text-rose-700 hover:bg-rose-50">{{ __('ui.menu.logout') }}</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        <div id="mobile-nav-overlay" class="fixed inset-0 z-40 hidden lg:hidden">
            <button type="button" class="absolute inset-0 bg-slate-900/45 backdrop-blur-sm" data-mobile-nav-close aria-label="Close menu"></button>
            <aside id="mobile-nav-drawer" class="absolute inset-y-0 left-0 flex h-full w-[86vw] max-w-sm flex-col border-r border-slate-200/70 shadow-2xl" style="--sidebar-tint-light: {{ $brandSidebarTint }}; background: var(--theme-sidebar, var(--sidebar-tint-light));">
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
                    @if($navCanWriteDevices)
                        <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.enroll-devices*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.enroll-devices') }}">Enroll Devices</a>
                    @endif
                    <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.dashboard') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.dashboard') }}">Overview</a>
                    @if($navCanManageDevices)
                        <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.devices') || request()->routeIs('admin.devices.show') || request()->routeIs('admin.devices.live') || request()->routeIs('admin.devices.update') || request()->routeIs('admin.devices.delete') || request()->routeIs('admin.devices.reenroll') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.devices') }}">Devices</a>
                        <details class="pt-1 group" {{ request()->routeIs('admin.assets*') ? 'open' : '' }}>
                            <summary class="list-none cursor-pointer rounded-lg px-3 py-2 flex items-center justify-between {{ request()->routeIs('admin.assets*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}">
                                <span>Asset Management</span>
                                <span class="expand-indicator text-xs"></span>
                            </summary>
                            <div class="mt-3 pl-2 space-y-2">
                                <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.assets') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.assets') }}">Asset Overview</a>
                                <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.assets.hardware') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.assets.hardware') }}">Hardware Inventory</a>
                                <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.assets.software') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.assets.software') }}">Software Inventory</a>
                                <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.assets.clients') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.assets.clients') }}">Client Management</a>
                            </div>
                        </details>
                    @endif

                    @if($navCanManageGroups)
                        <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.groups*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.groups') }}">Groups</a>
                    @endif
                    @if($navCanManagePackages)
                        <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.packages*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.packages') }}">Software Packages</a>
                    @endif
                    @if($navCanManagePolicies)
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
                    @endif

                    @if($navCanManageJobs)
                        <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.jobs*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.jobs') }}">Jobs</a>
                    @endif

                    @if($navShowEndpointIntelligence)
                        <details class="pt-1 group" {{ request()->routeIs('admin.intelligence.*') ? 'open' : '' }}>
                            <summary class="list-none cursor-pointer rounded-lg px-3 py-2 flex items-center justify-between {{ request()->routeIs('admin.intelligence.*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}">
                                <span>Endpoint Intelligence</span>
                                <span class="expand-indicator text-xs"></span>
                            </summary>
                            <div class="mt-3 pl-2 space-y-2">
                                @if($navCanReadHealth)
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-2 {{ request()->routeIs('admin.intelligence.health*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.health') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M4 12h3l2-5 4 10 2-5h5"/><path d="M12 21c5-3.2 8-6.3 8-10.4A4.6 4.6 0 0 0 12 7a4.6 4.6 0 0 0-8 3.6C4 14.7 7 17.8 12 21Z"/></svg>
                                        <span>Fleet Health</span>
                                    </a>
                                @endif
                                @if($navCanReadRisk)
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-2 {{ request()->routeIs('admin.intelligence.risk*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.risk') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M12 3 5 6v5c0 5 3.2 8.5 7 10 3.8-1.5 7-5 7-10V6l-7-3Z"/><path d="M12 8v4"/><circle cx="12" cy="15.5" r="0.9" fill="currentColor" stroke="none"/></svg>
                                        <span>Risk Dashboard</span>
                                    </a>
                                @endif
                                @if($navCanReadIncidents)
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-2 {{ request()->routeIs('admin.intelligence.incidents*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.incidents') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="7" r="2.5"/><circle cx="12" cy="18" r="2.5"/><path d="M8.2 7.2 15.6 17"/><path d="M15.7 8.8 12.9 15.6"/></svg>
                                        <span>Incidents</span>
                                    </a>
                                @endif
                                @if($navCanUseAssistant)
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-2 {{ request()->routeIs('admin.intelligence.assistant*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.assistant') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M12 3 9.9 8.4 4 10.5l5.9 2.1L12 18l2.1-5.4 5.9-2.1-5.9-2.1L12 3Z"/><path d="M5 3v3"/><path d="M19 18v3"/><path d="M3 5h3"/><path d="M18 19h3"/></svg>
                                        <span>AI Assistant</span>
                                    </a>
                                @endif
                                @if($navCanReadRemediation)
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-2 {{ request()->routeIs('admin.intelligence.remediation*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.remediation') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="m14.5 5.5 4 4"/><path d="M6.8 17.2 17 7a2.8 2.8 0 1 0-4-4L2.8 13.2a2 2 0 0 0-.5 1L2 20l5.8-.3a2 2 0 0 0 1-.5Z"/><path d="m12 8 4 4"/></svg>
                                        <span>Remediation</span>
                                    </a>
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-2 {{ request()->routeIs('admin.intelligence.actions*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.actions') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M3 12a9 9 0 1 0 2.6-6.4"/><path d="M3 4v5h5"/><path d="M12 7v5l3 2"/></svg>
                                        <span>Action History</span>
                                    </a>
                                @endif
                                @if($navCanApproveRemediation)
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-2 {{ request()->routeIs('admin.intelligence.approvals*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.approvals') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M12 3 5 6v6c0 4.7 3 7.9 7 9 4-1.1 7-4.3 7-9V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></svg>
                                        <span>Approvals</span>
                                    </a>
                                @endif
                                @if($navCanManageAutonomy)
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-2 {{ request()->routeIs('admin.intelligence.autonomy*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.autonomy') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M4 7h16"/><path d="M4 17h16"/><path d="M7 7v10"/><path d="M17 7v10"/><circle cx="7" cy="11" r="2.5"/><circle cx="17" cy="13" r="2.5"/></svg>
                                        <span>Autonomy</span>
                                    </a>
                                @endif
                                @if($navCanReadAutonomous)
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-2 {{ request()->routeIs('admin.intelligence.autonomous.decisions*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.autonomous.decisions') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M12 3 5 6v6c0 4.5 3 7.7 7 9 4-1.3 7-4 7-9V6l-7-3Z"/><path d="M8 12h8M12 8v8"/></svg>
                                        <span>Autonomous Decisions</span>
                                    </a>
                                @endif
                                @if($navCanManageAutonomous)
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-2 {{ request()->routeIs('admin.intelligence.autonomous.policies*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.autonomous.policies') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M4 7h16"/><path d="M4 17h16"/><path d="M7 7v10"/><path d="M17 7v10"/><circle cx="7" cy="11" r="2.5"/><circle cx="17" cy="13" r="2.5"/></svg>
                                        <span>Autonomous Policies</span>
                                    </a>
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-2 {{ request()->routeIs('admin.intelligence.autonomous.mappings*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.autonomous.mappings') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h10"/><circle cx="17" cy="18" r="2"/></svg>
                                        <span>Risk Mappings</span>
                                    </a>
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-2 {{ request()->routeIs('admin.intelligence.autonomous.catalog*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.autonomous.catalog') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M5 4h11a3 3 0 0 1 3 3v13H8a3 3 0 0 0-3 3V4Z"/><path d="M8 8h7M8 12h7M8 16h5"/></svg>
                                        <span>Action Catalog</span>
                                    </a>
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-2 {{ request()->routeIs('admin.intelligence.autonomous.simulate*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.autonomous.simulate') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M8 8a4 4 0 1 1 5.7 3.6L12 13l1.7 1.4A4 4 0 1 1 8 16"/><path d="M8 8h8"/><path d="M8 16h8"/></svg>
                                        <span>Simulation</span>
                                    </a>
                                @endif
                                @if($navCanReadHealth)
                                    <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-2 {{ request()->routeIs('admin.intelligence.tuning*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.intelligence.tuning') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true"><path d="M4 6h7"/><path d="M13 6h7"/><path d="M4 18h11"/><path d="M17 18h3"/><path d="M9 3v6"/><path d="M15 15v6"/><circle cx="12" cy="6" r="1.8"/><circle cx="16" cy="18" r="1.8"/></svg>
                                        <span>Tuning</span>
                                    </a>
                                @endif
                            </div>
                        </details>
                    @endif

                    <details class="pt-1 group" {{ request()->routeIs('admin.agent*') ? 'open' : '' }}>
                        <summary class="list-none cursor-pointer rounded-lg px-3 py-2 flex items-center justify-between {{ request()->routeIs('admin.agent*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}">
                            <span>Deployment Center</span>
                            <span class="expand-indicator text-xs"></span>
                        </summary>
                        <div class="mt-3 pl-2 space-y-2">
                            <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.agent*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.agent') }}">Agent Delivery</a>
                        </div>
                    </details>

                    @if($topbarIsSuperAdmin)
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

                        <details class="pt-1 group" {{ request()->routeIs('admin.access*') ? 'open' : '' }}>
                            <summary class="list-none cursor-pointer rounded-lg px-3 py-2 flex items-center justify-between {{ request()->routeIs('admin.access*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}">
                                <span>Access Control</span>
                                <span class="expand-indicator text-xs"></span>
                            </summary>
                            <div class="mt-3 pl-2 space-y-2">
                                <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-2 {{ request()->routeIs('admin.access.users') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.access.users') }}">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true">
                                        <path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"></path>
                                        <circle cx="9.5" cy="7" r="3"></circle>
                                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                                        <path d="M16 4.13a4 4 0 0 1 0 7.75"></path>
                                    </svg>
                                    <span>Users</span>
                                </a>
                                <a class="nav-link flex items-center gap-2 rounded-lg px-3 py-2 {{ request()->routeIs('admin.access.roles') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.access.roles') }}">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true">
                                        <path d="M12 3 4 7v6c0 4.5 3 7.7 8 9 5-1.3 8-4.5 8-9V7l-8-4Z"></path>
                                        <path d="m9.5 12 1.8 1.8L14.8 10"></path>
                                    </svg>
                                    <span>Roles</span>
                                </a>
                            </div>
                        </details>
                    @endif
                    @if($topbarCanManageSaasTenants)
                        <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.saas.dashboard*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.saas.dashboard') }}">SaaS Dashboard</a>
                        <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.saas.tenants*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.saas.tenants') }}">SaaS Tenants</a>
                    @endif
                    <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.docs*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.docs') }}">Docs</a>
                    <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.notes*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.notes') }}">Notes</a>
                    @if($navCanAccessAudit)
                        <a class="nav-link block rounded-lg px-3 py-2 {{ request()->routeIs('admin.audit*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.audit') }}">Audit Logs</a>
                    @endif
                </nav>
                <div class="border-t border-slate-200/70 px-4 py-4">
                    <form method="POST" action="{{ route('admin.locale.update') }}" class="mb-3 rounded-xl border border-slate-200 bg-white px-3 py-3 shadow-sm">
                        @csrf
                        <label class="flex items-center justify-between gap-3">
                            <span class="text-xs uppercase tracking-[0.18em] text-slate-500">{{ __('ui.locale.label') }}</span>
                            <select name="locale" onchange="this.form.submit()" class="min-w-[7rem] rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-700">
                                @foreach($supportedLocales as $localeCode => $localeLabel)
                                    <option value="{{ $localeCode }}" @selected($topbarCurrentLocale === $localeCode)>{{ $localeLabel }}</option>
                                @endforeach
                            </select>
                        </label>
                    </form>
                    <x-theme-select
                        id="mobile-theme-select"
                        wrapper-class="mb-3 flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-3 py-3 shadow-sm"
                        label-class="text-xs uppercase tracking-[0.18em] text-slate-500"
                        select-class="min-w-[7rem] text-xs"
                    />
                    <a href="{{ route('admin.profile') }}" class="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-700 shadow-sm">
                        <span>{{ __('ui.menu.open_profile') }}</span>
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
<div id="runtime-alert-agent-card" class="@if(! $agentBackendConfigured || $agentBackendRunning) hidden @endif rounded-xl border border-amber-200 bg-white p-4">
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
        <div class="w-full max-w-lg rounded-2xl border border-rose-200 bg-white">
            <div class="border-b border-slate-200 px-5 py-4">
                <h3 id="kill-switch-modal-title" class="text-base font-semibold text-slate-900">Engage Emergency Kill Switch</h3>
                <p class="mt-1 text-xs text-slate-600">This action requires admin password confirmation.</p>
            </div>
            <form id="kill-switch-modal-form" method="POST" action="{{ route('admin.ops.kill-switch') }}">
                @csrf
                <div class="space-y-3 px-5 py-4">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                        <p class="text-[11px] uppercase tracking-wide text-slate-500">Current Dispatch State</p>
                        <p id="kill-switch-modal-state" class="mt-1 text-sm font-semibold text-slate-900">Live</p>
                    </div>
                    <div id="kill-switch-modal-warning" class="brand-modal-note rounded-lg px-3 py-2 text-xs">
                        Pause all new command dispatch from the control plane until you explicitly resume it.
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600">
                        <p class="font-semibold text-slate-700">Impact</p>
                        <ul class="mt-1 list-disc space-y-1 pl-4">
                            <li>New command dispatch is blocked immediately.</li>
                            <li>Existing in-flight runs are not retroactively canceled.</li>
                            <li>Dispatch can only be restored by explicit admin action.</li>
                        </ul>
                    </div>
                    <input type="hidden" name="enabled" id="kill-switch-enabled" value="">
                    <div>
                        <label for="kill-switch-phrase" class="mb-1 block text-xs font-medium text-slate-600">Type confirmation phrase:</label>
                        <input id="kill-switch-phrase" name="confirmation_phrase" type="text" class="brand-modal-input w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 uppercase tracking-wide" autocomplete="off" />
                        <p class="mt-1 text-[11px] text-slate-500">Required phrase: <span id="kill-switch-phrase-target" class="font-mono font-semibold text-slate-700">PAUSE DISPATCH</span></p>
                    </div>
                    <div>
                        <label for="kill-switch-password" class="mb-1 block text-xs font-medium text-slate-600">Enter your admin password to confirm:</label>
                        <input id="kill-switch-password" name="admin_password" type="password" class="brand-modal-input w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900" autocomplete="current-password" />
                    </div>
                    <p id="kill-switch-modal-error" class="brand-modal-note hidden rounded-lg px-3 py-2 text-xs">Password is required.</p>
                </div>
                <div class="flex items-center justify-end gap-2 border-t border-slate-200 px-5 py-4">
                    <button id="kill-switch-cancel" type="button" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-700">Cancel</button>
                    <button id="kill-switch-confirm" type="submit" class="brand-modal-action rounded-lg px-3 py-2 text-xs font-medium disabled:cursor-not-allowed disabled:opacity-60" disabled>Pause Dispatch</button>
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
        const agentCard = document.getElementById('runtime-alert-agent-card');
        const agentMeta = document.getElementById('global-agent-backend-meta');
        const agentStatusLine = document.getElementById('agent-backend-status-line');
        const agentEndpointLine = document.getElementById('agent-backend-endpoint-line');
        const backendStatusUrl = @json(route('admin.agent.backend.status'));

        let popupDismissed = false;

        function syncPopupVisibility() {
            if (!popup) return;
            const hasAlert = !!(agentCard && !agentCard.classList.contains('hidden'));
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

        async function pollAgentStatus() {
            try {
                const res = await fetch(backendStatusUrl, {
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    }
                });
                if (res.status === 401 || res.status === 419) {
                    return;
                }
                if (!res.ok) return;
                const data = await res.json();
                const configured = data.configured !== false;
                if (!configured) {
                    if (agentMeta) agentMeta.textContent = 'Agent backend launcher is not configured.';
                    if (agentEndpointLine) agentEndpointLine.textContent = `${data.host}:${data.port}`;
                    if (agentStatusLine) {
                        agentStatusLine.innerHTML = 'Status: <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">not configured</span>';
                    }
                    if (agentCard) {
                        agentCard.classList.add('hidden');
                    }
                    syncPopupVisibility();
                    return;
                }

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
        pollAgentStatus();
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
        const stateNode = document.getElementById('kill-switch-modal-state');
        const warningNode = document.getElementById('kill-switch-modal-warning');
        const enabledField = document.getElementById('kill-switch-enabled');
        const phraseTarget = document.getElementById('kill-switch-phrase-target');
        const phraseInput = document.getElementById('kill-switch-phrase');
        const passwordInput = document.getElementById('kill-switch-password');
        const errorNode = document.getElementById('kill-switch-modal-error');
        const cancelBtn = document.getElementById('kill-switch-cancel');
        const confirmBtn = document.getElementById('kill-switch-confirm');
        const form = document.getElementById('kill-switch-modal-form');
        const initialEnabled = @json(old('enabled'));
        const initialError = @json($errors->first('kill_switch'));

        if (!modal || !titleNode || !stateNode || !warningNode || !enabledField || !phraseTarget || !phraseInput || !passwordInput || !errorNode || !cancelBtn || !confirmBtn || !form || triggers.length === 0) {
            return;
        }

        let expectedPhrase = 'PAUSE DISPATCH';

        function normalizePhrase(value) {
            return String(value || '').trim().toUpperCase();
        }

        function syncSubmitAvailability() {
            const validPhrase = normalizePhrase(phraseInput.value) === normalizePhrase(expectedPhrase);
            const hasPassword = passwordInput.value.trim() !== '';
            confirmBtn.disabled = !(validPhrase && hasPassword);
        }

        function closeModal() {
            modal.classList.add('hidden');
            enabledField.value = '';
            phraseInput.value = '';
            passwordInput.value = '';
            errorNode.textContent = 'Password is required.';
            errorNode.classList.add('hidden');
            confirmBtn.disabled = true;
            window.syncAdminModalState?.();
        }

        function openModal(options) {
            const enableSwitch = !!options.enableSwitch;
            expectedPhrase = options.requiredPhrase || (enableSwitch ? 'PAUSE DISPATCH' : 'RESTORE DISPATCH');
            titleNode.textContent = options.title || (enableSwitch ? 'Engage Emergency Kill Switch' : 'Restore Command Dispatch');
            stateNode.textContent = enableSwitch ? 'Live (new dispatch allowed)' : 'Halted (new dispatch blocked)';
            warningNode.textContent = options.description || (enableSwitch
                ? 'Immediately stop all new command dispatch from the control plane until an administrator explicitly restores it.'
                : 'Release the kill switch and allow new command dispatch to continue from the control plane.');
            warningNode.className = enableSwitch
                ? 'brand-modal-note rounded-lg px-3 py-2 text-xs'
                : 'brand-modal-note-safe rounded-lg px-3 py-2 text-xs';
            phraseTarget.textContent = expectedPhrase;
            phraseInput.placeholder = expectedPhrase;
            enabledField.value = enableSwitch ? '1' : '0';
            confirmBtn.textContent = options.confirmLabel || (enableSwitch ? 'Engage Kill Switch' : 'Restore Dispatch');
            confirmBtn.className = enableSwitch
                ? 'brand-modal-action rounded-lg px-3 py-2 text-xs font-medium disabled:cursor-not-allowed disabled:opacity-60'
                : 'brand-modal-action-safe rounded-lg px-3 py-2 text-xs font-medium disabled:cursor-not-allowed disabled:opacity-60';
            phraseInput.value = '';
            errorNode.classList.add('hidden');
            modal.classList.remove('hidden');
            confirmBtn.disabled = true;
            phraseInput.focus();
            window.syncAdminModalState?.();
        }

        triggers.forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                openModal({
                    enableSwitch: trigger.dataset.killSwitchEnabled === '1',
                    title: trigger.dataset.killSwitchTitle || '',
                    description: trigger.dataset.killSwitchDescription || '',
                    confirmLabel: trigger.dataset.killSwitchConfirm || '',
                    requiredPhrase: trigger.dataset.killSwitchPhrase || '',
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
        phraseInput.addEventListener('input', syncSubmitAvailability);
        passwordInput.addEventListener('input', syncSubmitAvailability);
        form.addEventListener('submit', function (event) {
            const hasPassword = passwordInput.value.trim() !== '';
            const phraseMatches = normalizePhrase(phraseInput.value) === normalizePhrase(expectedPhrase);
            if (hasPassword && phraseMatches) {
                return;
            }
            event.preventDefault();
            if (!phraseMatches) {
                errorNode.textContent = `Type "${expectedPhrase}" to confirm this action.`;
            } else {
                errorNode.textContent = 'Password is required.';
            }
            errorNode.classList.remove('hidden');
            if (!phraseMatches) {
                phraseInput.focus();
            } else {
                passwordInput.focus();
            }
            syncSubmitAvailability();
        });

        if (initialError) {
            openModal({
                enableSwitch: String(initialEnabled) === '1',
                title: String(initialEnabled) === '1' ? 'Engage Emergency Kill Switch' : 'Restore Command Dispatch',
                description: String(initialEnabled) === '1'
                    ? 'Immediately stop all new command dispatch from the control plane until an administrator explicitly restores it.'
                    : 'Release the kill switch and allow new command dispatch to continue from the control plane.',
                confirmLabel: String(initialEnabled) === '1' ? 'Engage Kill Switch' : 'Restore Dispatch',
                requiredPhrase: String(initialEnabled) === '1' ? 'PAUSE DISPATCH' : 'RESTORE DISPATCH',
            });
            errorNode.textContent = initialError;
            errorNode.classList.remove('hidden');
            syncSubmitAvailability();
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
            'Asset Management': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="3" y="4" width="18" height="14" rx="2"/><path d="M7 20h10"/><path d="M8 9h8M8 13h8"/></svg>',
            'Asset Overview': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M3 10.5L12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg>',
            'Hardware Inventory': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="7" y="7" width="10" height="10" rx="2"/><path d="M10 3v2M14 3v2M10 19v2M14 19v2M3 10h2M3 14h2M19 10h2M19 14h2"/></svg>',
            'Software Inventory': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3 4 7l8 4 8-4-8-4Z"/><path d="M4 7v10l8 4 8-4V7"/></svg>',
            'Client Management': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M16 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M8 12a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"/><path d="M2.5 20a5.5 5.5 0 0 1 11 0"/><path d="M13 20a5 5 0 0 1 8.5-3.5"/></svg>',
            'Policies': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3v18"/><path d="M6 7h12"/><path d="M6 17h12"/><path d="M8.5 7a3.5 3.5 0 0 1 0 7"/><path d="M15.5 17a3.5 3.5 0 0 0 0-7"/></svg>',
            'Policy Catalog': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M5 4h11a3 3 0 0 1 3 3v13H8a3 3 0 0 0-3 3V4Z"/><path d="M8 8h7M8 12h7M8 16h5"/></svg>',
            'Categories': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M3 8h18"/><path d="M3 12h18"/><path d="M3 16h18"/></svg>',
            'Policy Categories': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M3 8h18"/><path d="M3 12h18"/><path d="M3 16h18"/></svg>',
            'Jobs': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>',
            'Behavior Alerts': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3 4 7v6c0 5 3.5 7.8 8 9 4.5-1.2 8-4 8-9V7l-8-4Z"/><path d="M12 8v5"/><circle cx="12" cy="16.5" r="0.9"/></svg>',
            'Agent Delivery': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 2 3 7l9 5 9-5-9-5Z"/><path d="M3 17l9 5 9-5"/><path d="M3 12l9 5 9-5"/></svg>',
            'Settings': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M10.3 3h3.4l.6 2.2a7.8 7.8 0 0 1 1.8.8l2-1.1 2.4 2.4-1.1 2a7.8 7.8 0 0 1 .8 1.8l2.2.6v3.4l-2.2.6a7.8 7.8 0 0 1-.8 1.8l1.1 2-2.4 2.4-2-1.1a7.8 7.8 0 0 1-1.8.8l-.6 2.2h-3.4l-.6-2.2a7.8 7.8 0 0 1-1.8-.8l-2 1.1-2.4-2.4 1.1-2a7.8 7.8 0 0 1-.8-1.8L3 13.7v-3.4l2.2-.6a7.8 7.8 0 0 1 .8-1.8l-1.1-2 2.4-2.4 2 1.1a7.8 7.8 0 0 1 1.8-.8l.6-2.2Z"/><circle cx="12" cy="12" r="3"/></svg>',
            'General': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M10.3 3h3.4l.6 2.2a7.8 7.8 0 0 1 1.8.8l2-1.1 2.4 2.4-1.1 2a7.8 7.8 0 0 1 .8 1.8l2.2.6v3.4l-2.2.6a7.8 7.8 0 0 1-.8 1.8l1.1 2-2.4 2.4-2-1.1a7.8 7.8 0 0 1-1.8.8l-.6 2.2h-3.4l-.6-2.2a7.8 7.8 0 0 1-1.8-.8l-2 1.1-2.4-2.4 1.1-2a7.8 7.8 0 0 1-.8-1.8L3 13.7v-3.4l2.2-.6a7.8 7.8 0 0 1 .8-1.8l-1.1-2 2.4-2.4 2 1.1a7.8 7.8 0 0 1 1.8-.8l.6-2.2Z"/><circle cx="12" cy="12" r="3"/></svg>',
            'Branding': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3a9 9 0 0 0-9 9c0 4 3 7 7 7h1v2h2v-2h1a7 7 0 0 0 0-14h-2z"/><circle cx="8" cy="10" r="1"/><circle cx="12" cy="8" r="1"/><circle cx="15" cy="11" r="1"/></svg>',
            'Enroll Devices': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="3" y="4" width="18" height="14" rx="2"/><path d="M7 20h10"/><path d="m9 11 2 2 4-4"/></svg>',
            'Access': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>',
            'Access Control': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>',
            'SaaS Dashboard': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="5" rx="1.5"/><rect x="13" y="10" width="8" height="11" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/></svg>',
            'SaaS Tenants': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M4 7h16M4 12h16M4 17h10"/><circle cx="18" cy="17" r="3"/></svg>',
            'Docs': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M7 3h7l5 5v13H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M14 3v5h5"/></svg>',
            'Audit Logs': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/><path d="M11 8v3l2 2"/></svg>',
            'Policy Center': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3 4 7v6c0 5 3.5 7.8 8 9 4.5-1.2 8-4 8-9V7l-8-4Z"/></svg>',
            'Autonomous Remediation': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3 5 6v6c0 4.5 3 7.7 7 9 4-1.3 7-4 7-9V6l-7-3Z"/><path d="M8 12h8M12 8v8"/></svg>',
            'Deployment Center': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 2v20"/><path d="M5 7h14"/><path d="M7 12h10"/><path d="M9 17h6"/></svg>',
            'Endpoint Intelligence': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><circle cx="6" cy="12" r="1.5"/><circle cx="18" cy="6" r="1.5"/><circle cx="18" cy="18" r="1.5"/><path d="M7.5 12h5"/><path d="m14.5 12 2.2-4.1"/><path d="m14.5 12 2.2 4.1"/></svg>',
            'Fleet Health': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M4 12h3l2-5 4 10 2-5h5"/><path d="M12 21c5-3.2 8-6.3 8-10.4A4.6 4.6 0 0 0 12 7a4.6 4.6 0 0 0-8 3.6C4 14.7 7 17.8 12 21Z"/></svg>',
            'Fleet Health Overview': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M4 12h3l2-5 4 10 2-5h5"/><path d="M12 21c5-3.2 8-6.3 8-10.4A4.6 4.6 0 0 0 12 7a4.6 4.6 0 0 0-8 3.6C4 14.7 7 17.8 12 21Z"/></svg>',
            'Risk Dashboard': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3 5 6v5c0 5 3.2 8.5 7 10 3.8-1.5 7-5 7-10V6l-7-3Z"/><path d="M12 8v4"/><circle cx="12" cy="15.5" r="0.9" fill="currentColor" stroke="none"/></svg>',
            'Risk & Threat Dashboard': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3 5 6v5c0 5 3.2 8.5 7 10 3.8-1.5 7-5 7-10V6l-7-3Z"/><path d="M12 8v4"/><circle cx="12" cy="15.5" r="0.9" fill="currentColor" stroke="none"/></svg>',
            'Incidents': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="7" r="2.5"/><circle cx="12" cy="18" r="2.5"/><path d="M8.2 7.2 15.6 17"/><path d="M15.7 8.8 12.9 15.6"/></svg>',
            'Correlated Incident Explorer': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="7" r="2.5"/><circle cx="12" cy="18" r="2.5"/><path d="M8.2 7.2 15.6 17"/><path d="M15.7 8.8 12.9 15.6"/></svg>',
            'AI Assistant': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3 9.9 8.4 4 10.5l5.9 2.1L12 18l2.1-5.4 5.9-2.1-5.9-2.1L12 3Z"/><path d="M5 3v3"/><path d="M19 18v3"/><path d="M3 5h3"/><path d="M18 19h3"/></svg>',
            'AI Ops Assistant': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3 9.9 8.4 4 10.5l5.9 2.1L12 18l2.1-5.4 5.9-2.1-5.9-2.1L12 3Z"/><path d="M5 3v3"/><path d="M19 18v3"/><path d="M3 5h3"/><path d="M18 19h3"/></svg>',
            'Remediation': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="m14.5 5.5 4 4"/><path d="M6.8 17.2 17 7a2.8 2.8 0 1 0-4-4L2.8 13.2a2 2 0 0 0-.5 1L2 20l5.8-.3a2 2 0 0 0 1-.5Z"/><path d="m12 8 4 4"/></svg>',
            'Remediation Queue': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="m14.5 5.5 4 4"/><path d="M6.8 17.2 17 7a2.8 2.8 0 1 0-4-4L2.8 13.2a2 2 0 0 0-.5 1L2 20l5.8-.3a2 2 0 0 0 1-.5Z"/><path d="m12 8 4 4"/></svg>',
            'Approvals': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3 5 6v6c0 4.7 3 7.9 7 9 4-1.1 7-4.3 7-9V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></svg>',
            'Approval Center': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3 5 6v6c0 4.7 3 7.9 7 9 4-1.1 7-4.3 7-9V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></svg>',
            'Action History': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M3 12a9 9 0 1 0 2.6-6.4"/><path d="M3 4v5h5"/><path d="M12 7v5l3 2"/></svg>',
            'Autonomy': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M4 7h16"/><path d="M4 17h16"/><path d="M7 7v10"/><path d="M17 7v10"/><circle cx="7" cy="11" r="2.5"/><circle cx="17" cy="13" r="2.5"/></svg>',
            'Autonomy Policy Settings': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M4 7h16"/><path d="M4 17h16"/><path d="M7 7v10"/><path d="M17 7v10"/><circle cx="7" cy="11" r="2.5"/><circle cx="17" cy="13" r="2.5"/></svg>',
            'Tuning': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M4 6h7"/><path d="M13 6h7"/><path d="M4 18h11"/><path d="M17 18h3"/><path d="M9 3v6"/><path d="M15 15v6"/><circle cx="12" cy="6" r="1.8"/><circle cx="16" cy="18" r="1.8"/></svg>',
            'Engine / Rule Tuning': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M4 6h7"/><path d="M13 6h7"/><path d="M4 18h11"/><path d="M17 18h3"/><path d="M9 3v6"/><path d="M15 15v6"/><circle cx="12" cy="6" r="1.8"/><circle cx="16" cy="18" r="1.8"/></svg>',
            'Device Executive Summary': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M7 3h7l5 5v13H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M14 3v5h5"/><path d="M9 12h6"/><path d="M9 16h6"/></svg>'
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

