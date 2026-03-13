<?php

namespace Tests\Feature\Web;

use App\Models\ControlPlaneSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BehaviorAiLiveStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_behavior_ai_live_status_returns_current_cockpit_payload(): void
    {
        $user = User::factory()->create();
        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'behavior.ai_threshold'],
            ['value' => ['value' => '0.5']]
        );
        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'behavior.pipeline.last_retrained_at'],
            ['value' => ['value' => '2026-03-12T10:01:07+02:00']]
        );

        $response = $this->actingAs($user)->getJson(route('admin.behavior-ai.live-status'));
        $response->assertOk()->assertJsonStructure([
            'stats' => [
                'stream_queued',
                'stream_failed',
                'cases_pending',
                'cases_high',
                'recommendations_pending',
                'recommendations_applied',
                'feedback_total',
                'feedback_approved_30d',
                'feedback_rejected_30d',
            ],
            'threshold',
            'last_retrained_at',
            'runtime' => [
                'queue_running',
                'scheduler_running',
                'runtime_running',
                'checked_at',
            ],
            'runtime_healthy',
            'operations_backlog',
            'approval' => [
                'ratio',
                'approved_30d',
                'rejected_30d',
            ],
            'updated_at',
        ])->assertJsonPath('threshold', '0.5')
            ->assertJsonPath('last_retrained_at', '2026-03-12T10:01:07+02:00');

        $payload = $response->json();
        $expectedBacklog = (int) ($payload['stats']['stream_queued'] ?? 0)
            + (int) ($payload['stats']['stream_failed'] ?? 0)
            + (int) ($payload['stats']['cases_pending'] ?? 0);
        $this->assertSame($expectedBacklog, (int) ($payload['operations_backlog'] ?? -1));
    }
}

