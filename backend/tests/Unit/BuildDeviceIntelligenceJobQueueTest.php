<?php

namespace Tests\Unit;

use App\Jobs\BuildDeviceIntelligenceJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildDeviceIntelligenceJobQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_intelligence_job_uses_configured_queue_name(): void
    {
        config()->set('services.endpoint_intelligence.queue', 'default');
        $job = new BuildDeviceIntelligenceJob('device-1');
        $this->assertSame('default', $job->queue);

        config()->set('services.endpoint_intelligence.queue', 'health_compute');
        $job = new BuildDeviceIntelligenceJob('device-2');
        $this->assertSame('health_compute', $job->queue);
    }
}

