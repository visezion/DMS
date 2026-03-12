<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_event_streams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('device_id')->index();
            $table->uuid('behavior_log_id')->nullable()->index();
            $table->string('event_type', 64)->index();
            $table->timestamp('occurred_at')->index();
            $table->json('payload');
            $table->string('status', 24)->default('queued')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'ai_stream_status_created_idx');
        });

        Schema::create('behavior_anomaly_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('stream_event_id')->nullable()->index();
            $table->uuid('behavior_log_id')->index();
            $table->uuid('device_id')->index();
            $table->decimal('risk_score', 5, 4);
            $table->string('severity', 16)->index();
            $table->string('status', 32)->default('pending_review')->index();
            $table->string('summary');
            $table->json('context')->nullable();
            $table->json('detector_weights')->nullable();
            $table->timestamp('detected_at')->index();
            $table->unsignedBigInteger('reviewed_by')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique('behavior_log_id', 'behavior_anomaly_case_log_unique');
            $table->index(['device_id', 'severity', 'detected_at'], 'behavior_anomaly_case_device_sev_detected_idx');
        });

        Schema::create('behavior_anomaly_signals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('anomaly_case_id')->index();
            $table->string('detector_key', 64)->index();
            $table->decimal('score', 5, 4);
            $table->decimal('confidence', 5, 4);
            $table->decimal('weight', 5, 4);
            $table->json('details')->nullable();
            $table->timestamps();

            $table->unique(['anomaly_case_id', 'detector_key'], 'behavior_anomaly_signal_case_detector_unique');
        });

        Schema::create('behavior_policy_recommendations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('anomaly_case_id')->index();
            $table->uuid('policy_version_id')->nullable()->index();
            $table->string('recommended_action', 32)->index();
            $table->unsignedSmallInteger('rank')->default(1);
            $table->decimal('score', 6, 4);
            $table->json('rationale')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedBigInteger('reviewed_by')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->uuid('applied_job_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['anomaly_case_id', 'rank'], 'behavior_policy_reco_case_rank_unique');
        });

        Schema::create('behavior_policy_feedback', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recommendation_id')->index();
            $table->uuid('anomaly_case_id')->index();
            $table->unsignedBigInteger('reviewer_user_id')->nullable()->index();
            $table->string('decision', 32)->index();
            $table->uuid('selected_policy_version_id')->nullable()->index();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['decision', 'created_at'], 'behavior_feedback_decision_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behavior_policy_feedback');
        Schema::dropIfExists('behavior_policy_recommendations');
        Schema::dropIfExists('behavior_anomaly_signals');
        Schema::dropIfExists('behavior_anomaly_cases');
        Schema::dropIfExists('ai_event_streams');
    }
};

