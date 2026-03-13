<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('behavior_remediation_executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('anomaly_case_id')->nullable()->index();
            $table->uuid('device_id')->index();
            $table->uuid('behavior_log_id')->nullable()->index();
            $table->string('remediation_key', 80)->index();
            $table->string('action_type', 48)->index();
            $table->string('status', 24)->default('queued')->index();
            $table->decimal('risk_score', 5, 4)->default(0);
            $table->decimal('trigger_score', 5, 4)->default(0);
            $table->string('reason', 500)->nullable();
            $table->json('payload')->nullable();
            $table->uuid('dispatched_job_id')->nullable()->index();
            $table->text('failure_reason')->nullable();
            $table->timestamp('detected_at')->nullable()->index();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->unique(['anomaly_case_id', 'remediation_key'], 'behavior_remediation_case_key_unique');
            $table->index(['device_id', 'created_at'], 'behavior_remediation_device_created_idx');
            $table->index(['status', 'created_at'], 'behavior_remediation_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behavior_remediation_executions');
    }
};

