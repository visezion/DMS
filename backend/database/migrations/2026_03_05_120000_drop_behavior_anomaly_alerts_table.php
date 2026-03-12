<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('behavior_anomaly_alerts');
    }

    public function down(): void
    {
        Schema::create('behavior_anomaly_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('device_id')->index();
            $table->uuid('behavior_log_id')->nullable()->index();
            $table->string('rule_key', 100)->index();
            $table->string('fingerprint', 190)->unique();
            $table->string('severity', 32)->default('medium');
            $table->string('status', 32)->default('pending')->index();
            $table->string('summary');
            $table->json('details')->nullable();
            $table->uuid('triggered_job_id')->nullable()->index();
            $table->unsignedBigInteger('reviewed_by')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('first_seen_at')->index();
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();

            $table->index(['device_id', 'status', 'last_seen_at'], 'behavior_alert_device_status_last_seen_idx');
        });
    }
};

