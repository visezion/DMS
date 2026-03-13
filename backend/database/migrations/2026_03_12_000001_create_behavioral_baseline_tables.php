<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_behavior_baselines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('device_id')->unique();
            $table->unsignedInteger('sample_count')->default(0);
            $table->json('profile')->nullable();
            $table->timestamp('last_event_at')->nullable()->index();
            $table->timestamp('last_model_update_at')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'updated_at'], 'behavior_baseline_device_updated_idx');
        });

        Schema::create('device_behavior_drift_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('device_id')->index();
            $table->uuid('behavior_log_id')->nullable()->index();
            $table->uuid('anomaly_case_id')->nullable()->index();
            $table->decimal('drift_score', 5, 4);
            $table->string('severity', 16)->index();
            $table->json('drift_categories')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('detected_at')->index();
            $table->timestamps();

            $table->unique(['behavior_log_id'], 'behavior_drift_behavior_log_unique');
            $table->index(['device_id', 'severity', 'detected_at'], 'behavior_drift_device_severity_detected_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_behavior_drift_events');
        Schema::dropIfExists('device_behavior_baselines');
    }
};

