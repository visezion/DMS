<?php

namespace Tests\Feature\Web;

use App\Models\ControlPlaneSetting;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class ProfileMfaManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_enable_mfa_after_setup_secret_exists(): void
    {
        $user = User::factory()->create([
            'mfa_enabled' => false,
            'mfa_secret' => Crypt::encryptString('ABCDEFGHIJKLMNOPQRSTUV234567'),
        ]);

        $totpService = $this->createMock(TotpService::class);
        $totpService->method('verifyCode')->willReturn(true);
        $this->app->instance(TotpService::class, $totpService);

        $this->actingAs($user)
            ->post(route('admin.profile.mfa.enable'), [
                'code' => '123456',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertTrue((bool) $user->fresh()->mfa_enabled);
    }

    public function test_disable_mfa_is_blocked_when_global_policy_requires_mfa(): void
    {
        $user = User::factory()->create([
            'mfa_enabled' => true,
            'mfa_secret' => Crypt::encryptString('ABCDEFGHIJKLMNOPQRSTUV234567'),
        ]);

        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'auth.require_mfa'],
            ['value' => ['value' => true], 'updated_by' => $user->id]
        );

        $this->actingAs($user)
            ->from(route('admin.profile'))
            ->post(route('admin.profile.mfa.disable'), [
                'password' => 'password',
            ])
            ->assertRedirect(route('admin.profile'))
            ->assertSessionHasErrors('profile_mfa');

        $fresh = $user->fresh();
        $this->assertTrue((bool) $fresh->mfa_enabled);
        $this->assertNotNull($fresh->mfa_secret);
    }

    public function test_rotate_secret_is_blocked_when_policy_requires_mfa_and_user_is_enabled(): void
    {
        $user = User::factory()->create([
            'mfa_enabled' => true,
            'mfa_secret' => Crypt::encryptString('ABCDEFGHIJKLMNOPQRSTUV234567'),
        ]);

        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'auth.require_mfa'],
            ['value' => ['value' => true], 'updated_by' => $user->id]
        );

        $beforeSecret = (string) $user->mfa_secret;

        $this->actingAs($user)
            ->from(route('admin.profile'))
            ->post(route('admin.profile.mfa.setup'))
            ->assertRedirect(route('admin.profile'))
            ->assertSessionHasErrors('profile_mfa');

        $fresh = $user->fresh();
        $this->assertTrue((bool) $fresh->mfa_enabled);
        $this->assertSame($beforeSecret, (string) $fresh->mfa_secret);
    }
}

