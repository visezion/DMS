<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ControlPlaneSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminAuthController extends Controller
{
    public function loginForm(Request $request): View
    {
        $puzzleRequired = true;
        $puzzleQuestion = null;
        $captchaImage = (string) $request->session()->get('admin_login_captcha_image', '');
        if ($captchaImage === '') {
            $captchaImage = $this->issueCaptchaChallenge($request);
        }

        return view('admin.auth.login', [
            'puzzleRequired' => $puzzleRequired,
            'puzzleQuestion' => $puzzleQuestion,
            'captchaImage' => $captchaImage,
            'signupEnabled' => $this->selfSignupEnabled(),
        ]);
    }

    public function registerForm(): View
    {
        abort_unless($this->selfSignupEnabled(), 404);

        return view('admin.auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        abort_unless($this->selfSignupEnabled(), 404);

        $data = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
            'organization_slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9._-]+$/i'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $slugInput = trim((string) ($data['organization_slug'] ?? ''));
        if ($slugInput === '') {
            $slugInput = (string) $data['organization_name'];
        }
        $tenantSlug = Str::slug($slugInput);
        if ($tenantSlug === '') {
            return back()->withErrors(['organization_slug' => 'Organization slug is invalid.'])->withInput();
        }

        $tenantExists = Tenant::query()->where('slug', $tenantSlug)->exists();
        if ($tenantExists) {
            return back()->withErrors(['organization_slug' => 'Organization slug already exists.'])->withInput();
        }

        [$tenant, $user] = DB::transaction(function () use ($data, $tenantSlug) {
            $tenant = Tenant::query()->create([
                'id' => (string) Str::uuid(),
                'name' => trim((string) $data['organization_name']),
                'slug' => $tenantSlug,
                'status' => 'active',
                'settings' => [],
            ]);

            $this->ensureBasePermissions();

            $tenantSuperAdminRole = Role::query()
                ->withoutGlobalScope('tenant')
                ->firstOrCreate([
                    'slug' => 'super-admin',
                    'tenant_id' => $tenant->id,
                ], [
                    'id' => (string) Str::uuid(),
                    'name' => 'Super Admin',
                ]);

            $tenantSuperAdminRole->permissions()->sync(
                Permission::query()->pluck('id')
            );

            $user = User::query()->create([
                'tenant_id' => $tenant->id,
                'name' => trim((string) $data['name']),
                'email' => strtolower(trim((string) $data['email'])),
                'password' => (string) $data['password'],
                'is_active' => true,
            ]);
            $user->roles()->syncWithoutDetaching([$tenantSuperAdminRole->id]);

            return [$tenant, $user];
        });

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()
            ->route('admin.dashboard')
            ->with('status', 'Organization created: '.$tenant->name.'. Welcome!');
    }

    public function refreshCaptcha(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'captcha_image' => $this->issueCaptchaChallenge($request),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $email = strtolower((string) $credentials['email']);
        $ip = (string) $request->ip();
        $policy = $this->authPolicy();
        $puzzleRequired = true;

        if ($puzzleRequired) {
            $request->validate([
                'captcha_answer' => ['required', 'string', 'max:16'],
                'company_website' => ['nullable', 'string', 'max:255'],
            ]);

            if (
                ! $this->verifyCaptchaChallenge($request, (string) $request->input('captcha_answer', ''))
                || trim((string) $request->input('company_website', '')) !== ''
            ) {
                $captchaImage = $this->issueCaptchaChallenge($request);
                return back()
                    ->withErrors(['email' => 'Captcha verification failed. Please retry.'])
                    ->withInput(['email' => $email])
                    ->with('puzzle_required', true)
                    ->with('captcha_image', $captchaImage);
            }
        }

        if ($this->isLockedOut($email, $ip)) {
            return back()->withErrors(['email' => $this->lockoutErrorMessage($email, $ip)])->onlyInput('email');
        }

        $user = User::query()
            ->where('email', $email)
            ->first();

        if (! $user || ! Hash::check((string) $credentials['password'], (string) $user->password)) {
            $this->registerFailedAttempt($email, $ip, $policy);
            $this->issueCaptchaChallenge($request);
            if ($this->isLockedOut($email, $ip)) {
                return back()->withErrors(['email' => $this->lockoutErrorMessage($email, $ip)])->onlyInput('email');
            }
            return back()->withErrors(['email' => 'Invalid credentials'])->onlyInput('email');
        }

        if (! (bool) $user->is_active) {
            return back()->withErrors(['email' => 'Account is disabled.'])->onlyInput('email');
        }

        $requireMfa = $this->settingBool('auth.require_mfa', false);
        $userHasMfa = (bool) $user->mfa_enabled && is_string($user->mfa_secret) && trim($user->mfa_secret) !== '';
        if ($requireMfa && ! $userHasMfa) {
            return back()->withErrors([
                'email' => 'MFA is required by admin policy. Your account is not enrolled for MFA.',
            ])->onlyInput('email');
        }

        $this->clearLoginPuzzle($request);
        if ($userHasMfa) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->regenerate();
            $request->session()->put('admin_mfa_user_id', (int) $user->id);
            $request->session()->put('admin_mfa_remember', true);
            $request->session()->put('admin_mfa_started_at', now()->toIso8601String());

            return redirect()->route('admin.login.mfa.form');
        }

        $this->clearFailedAttempts((string) $user->email, $ip);
        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard');
    }

    public function mfaForm(Request $request): View|RedirectResponse
    {
        $userId = (int) $request->session()->get('admin_mfa_user_id', 0);
        if ($userId <= 0) {
            return redirect()->route('admin.login');
        }

        $user = User::query()->find($userId);
        if (! $user || ! (bool) $user->mfa_enabled) {
            $request->session()->forget(['admin_mfa_user_id', 'admin_mfa_remember', 'admin_mfa_started_at']);
            return redirect()->route('admin.login')->withErrors(['email' => 'MFA session is no longer valid.']);
        }

        return view('admin.auth.mfa', [
            'email' => (string) $user->email,
        ]);
    }

    public function verifyMfa(Request $request, TotpService $totpService): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:8'],
        ]);

        $userId = (int) $request->session()->get('admin_mfa_user_id', 0);
        if ($userId <= 0) {
            return redirect()->route('admin.login');
        }

        $user = User::query()->find($userId);
        if (! $user || ! (bool) $user->mfa_enabled || ! is_string($user->mfa_secret) || trim($user->mfa_secret) === '') {
            $request->session()->forget(['admin_mfa_user_id', 'admin_mfa_remember', 'admin_mfa_started_at']);
            return redirect()->route('admin.login')->withErrors(['email' => 'MFA session is no longer valid.']);
        }
        $email = strtolower((string) $user->email);
        $ip = (string) $request->ip();
        $policy = $this->authPolicy();

        if ($this->isLockedOut($email, $ip)) {
            return back()->withErrors(['code' => $this->lockoutErrorMessage($email, $ip)]);
        }

        try {
            $secret = Crypt::decryptString($user->mfa_secret);
        } catch (\Throwable) {
            return back()->withErrors(['code' => 'Stored MFA secret is invalid. Reconfigure MFA in profile settings.']);
        }

        if (! $totpService->verifyCode($secret, (string) $data['code'])) {
            $this->registerFailedAttempt($email, $ip, $policy);
            if ($this->isLockedOut($email, $ip)) {
                return back()->withErrors(['code' => $this->lockoutErrorMessage($email, $ip)]);
            }
            return back()->withErrors(['code' => 'Invalid MFA code.']);
        }

        $this->clearFailedAttempts($email, $ip);
        Auth::login($user, (bool) $request->session()->get('admin_mfa_remember', true));
        $request->session()->forget(['admin_mfa_user_id', 'admin_mfa_remember', 'admin_mfa_started_at']);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard');
    }

    public function cancelMfa(Request $request): RedirectResponse
    {
        $request->session()->forget(['admin_mfa_user_id', 'admin_mfa_remember', 'admin_mfa_started_at']);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    private function authPolicy(): array
    {
        return [
            'max_login_attempts' => max(1, $this->settingInt('auth.max_login_attempts', 5)),
            'lockout_minutes' => max(1, $this->settingInt('auth.lockout_minutes', 15)),
        ];
    }

    private function settingInt(string $key, int $default): int
    {
        $setting = ControlPlaneSetting::query()->find($key);
        if ($setting && is_array($setting->value) && array_key_exists('value', $setting->value)) {
            return (int) $setting->value['value'];
        }

        return $default;
    }

    private function settingBool(string $key, bool $default): bool
    {
        $setting = ControlPlaneSetting::query()->find($key);
        if ($setting && is_array($setting->value) && array_key_exists('value', $setting->value)) {
            return (bool) $setting->value['value'];
        }

        return $default;
    }

    private function lockoutAttemptKey(string $email, string $ip): string
    {
        return 'admin.auth.lockout.attempts:'.sha1(strtolower(trim($email)).'|'.$ip);
    }

    private function lockoutUntilKey(string $email, string $ip): string
    {
        return 'admin.auth.lockout.until:'.sha1(strtolower(trim($email)).'|'.$ip);
    }

    private function isLockedOut(string $email, string $ip): bool
    {
        $lockedUntilTs = (int) Cache::get($this->lockoutUntilKey($email, $ip), 0);
        return $lockedUntilTs > now()->timestamp;
    }

    private function lockoutErrorMessage(string $email, string $ip): string
    {
        $lockedUntilTs = (int) Cache::get($this->lockoutUntilKey($email, $ip), 0);
        if ($lockedUntilTs <= now()->timestamp) {
            return 'Too many failed login attempts. Please try again later.';
        }

        $minutes = max(1, (int) ceil(($lockedUntilTs - now()->timestamp) / 60));
        return "Too many failed login attempts. Try again in {$minutes} minute(s).";
    }

    private function registerFailedAttempt(string $email, string $ip, array $policy): void
    {
        $attemptKey = $this->lockoutAttemptKey($email, $ip);
        $untilKey = $this->lockoutUntilKey($email, $ip);
        $lockoutMinutes = (int) ($policy['lockout_minutes'] ?? 15);
        $maxAttempts = (int) ($policy['max_login_attempts'] ?? 5);

        $attempts = (int) Cache::get($attemptKey, 0) + 1;
        Cache::put($attemptKey, $attempts, now()->addMinutes($lockoutMinutes));

        if ($attempts >= $maxAttempts) {
            $lockedUntilTs = now()->addMinutes($lockoutMinutes)->timestamp;
            Cache::put($untilKey, $lockedUntilTs, now()->addMinutes($lockoutMinutes));
            Cache::forget($attemptKey);
        }
    }

    private function clearFailedAttempts(string $email, string $ip): void
    {
        Cache::forget($this->lockoutAttemptKey($email, $ip));
        Cache::forget($this->lockoutUntilKey($email, $ip));
    }

    private function issueCaptchaChallenge(Request $request): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $chars = str_split($code);
        $x = 24;
        $charSvg = '';
        foreach ($chars as $char) {
            $rotate = random_int(-24, 24);
            $y = random_int(34, 48);
            $fill = sprintf('#%02x%02x%02x', random_int(40, 120), random_int(40, 120), random_int(40, 120));
            $charSvg .= '<text x="'.$x.'" y="'.$y.'" font-size="42" font-family="Verdana,Arial,sans-serif" fill="'.$fill.'" transform="rotate('.$rotate.' '.$x.' '.$y.')">'.$char.'</text>';
            $x += random_int(28, 34);
        }

        $noise = '';
        for ($i = 0; $i < 10; $i++) {
            $x1 = random_int(0, 260);
            $y1 = random_int(0, 74);
            $x2 = random_int(0, 260);
            $y2 = random_int(0, 74);
            $stroke = sprintf('rgba(%d,%d,%d,0.45)', random_int(80, 180), random_int(80, 180), random_int(80, 180));
            $noise .= '<line x1="'.$x1.'" y1="'.$y1.'" x2="'.$x2.'" y2="'.$y2.'" stroke="'.$stroke.'" stroke-width="1.8" />';
        }
        for ($i = 0; $i < 20; $i++) {
            $cx = random_int(4, 256);
            $cy = random_int(4, 70);
            $r = random_int(1, 2);
            $fill = sprintf('rgba(%d,%d,%d,0.35)', random_int(80, 180), random_int(80, 180), random_int(80, 180));
            $noise .= '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$r.'" fill="'.$fill.'" />';
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="260" height="74" viewBox="0 0 260 74">'
            .'<rect width="260" height="74" rx="8" ry="8" fill="#f8fafc"/>'
            .$noise
            .$charSvg
            .'</svg>';
        $image = 'data:image/svg+xml;base64,'.base64_encode($svg);

        $request->session()->put('admin_login_puzzle_required', true);
        $request->session()->put('admin_login_captcha_code', strtolower($code));
        $request->session()->put('admin_login_captcha_image', $image);

        return $image;
    }

    private function verifyCaptchaChallenge(Request $request, string $answer): bool
    {
        $expected = trim((string) $request->session()->get('admin_login_captcha_code', ''));
        if ($expected === '') {
            return false;
        }

        return strtolower(trim($answer)) === $expected;
    }

    private function clearLoginPuzzle(Request $request): void
    {
        $request->session()->forget([
            'admin_login_puzzle_required',
            'admin_login_puzzle_question',
            'admin_login_puzzle_answer',
            'admin_login_captcha_code',
            'admin_login_captcha_image',
        ]);
    }

    /**
     * @return string[]
     */
    private function defaultPermissionSlugs(): array
    {
        return [
            'devices.read', 'devices.write',
            'groups.read', 'groups.write',
            'packages.read', 'packages.write',
            'policies.read', 'policies.write',
            'jobs.read', 'jobs.write',
            'audit.read',
            'access.read', 'access.write',
        ];
    }

    private function ensureBasePermissions(): void
    {
        foreach ($this->defaultPermissionSlugs() as $slug) {
            Permission::query()->firstOrCreate([
                'slug' => $slug,
            ], [
                'id' => (string) Str::uuid(),
                'name' => $slug,
            ]);
        }
    }

    private function selfSignupEnabled(): bool
    {
        return filter_var((string) env('DMS_SELF_SIGNUP_ENABLED', 'true'), FILTER_VALIDATE_BOOL);
    }
}
