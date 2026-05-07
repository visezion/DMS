<?php

namespace App\Providers;

use App\Events\AutonomousTriggerDetected;
use App\Listeners\QueueAutonomousEvaluation;
use App\Models\CorrelatedIncident;
use App\Models\DeviceHealthScore;
use App\Models\DeviceRiskScore;
use App\Models\JobRun;
use App\Models\ThreatFinding;
use App\Observers\CorrelatedIncidentObserver;
use App\Observers\DeviceHealthScoreObserver;
use App\Observers\DeviceRiskScoreObserver;
use App\Observers\JobRunObserver;
use App\Observers\ThreatFindingObserver;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ThreatFinding::observe(ThreatFindingObserver::class);
        CorrelatedIncident::observe(CorrelatedIncidentObserver::class);
        DeviceRiskScore::observe(DeviceRiskScoreObserver::class);
        DeviceHealthScore::observe(DeviceHealthScoreObserver::class);
        JobRun::observe(JobRunObserver::class);

        Event::listen(
            AutonomousTriggerDetected::class,
            QueueAutonomousEvaluation::class
        );
    }
}
