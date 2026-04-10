<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_health_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('device_id')->index();
            $table->timestamp('snapshot_at')->index();
            $table->string('source', 32)->default('checkin');
            $table->string('ingest_version', 24)->default('v1');
            $table->json('metrics');
            $table->timestamps();

            $table->index(['tenant_id', 'device_id', 'snapshot_at'], 'health_snapshots_tenant_device_time_idx');
        });

        Schema::create('device_health_scores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('device_id')->index();
            $table->uuid('snapshot_id')->index();
            $table->decimal('score', 5, 2);
            $table->string('band', 16)->index();
            $table->decimal('predicted_failure_risk', 5, 2)->default(0);
            $table->json('component_scores')->nullable();
            $table->json('contributors')->nullable();
            $table->timestamp('scored_at')->index();
            $table->timestamps();

            $table->index(['tenant_id', 'device_id', 'scored_at'], 'health_scores_tenant_device_scored_idx');
        });

        Schema::create('device_risk_scores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('device_id')->index();
            $table->decimal('score', 5, 2);
            $table->string('severity', 16)->index();
            $table->decimal('confidence', 5, 2)->default(0);
            $table->json('factor_breakdown')->nullable();
            $table->timestamp('scored_at')->index();
            $table->timestamps();

            $table->index(['tenant_id', 'device_id', 'scored_at'], 'risk_scores_tenant_device_scored_idx');
        });

        Schema::create('threat_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('device_id')->index();
            $table->string('session_id', 64)->nullable()->index();
            $table->string('finding_type', 80)->index();
            $table->string('severity', 16)->index();
            $table->decimal('confidence', 5, 2)->default(0);
            $table->string('status', 24)->default('open')->index();
            $table->string('fingerprint', 190)->index();
            $table->string('mitre_tactic', 32)->nullable();
            $table->string('mitre_technique', 32)->nullable();
            $table->json('evidence')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('first_seen_at')->index();
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'fingerprint', 'status'], 'threat_findings_tenant_fingerprint_status_unique');
        });

        Schema::create('correlated_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('primary_device_id')->nullable()->index();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('severity', 16)->index();
            $table->decimal('confidence', 5, 2)->default(0);
            $table->string('status', 24)->default('open')->index();
            $table->json('root_cause')->nullable();
            $table->timestamp('opened_at')->index();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'severity', 'opened_at'], 'incidents_tenant_status_severity_opened_idx');
        });

        Schema::create('incident_timelines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('incident_id')->index();
            $table->unsignedInteger('version')->default(1);
            $table->text('summary')->nullable();
            $table->longText('narrative')->nullable();
            $table->string('generated_by', 24)->default('system');
            $table->timestamp('generated_at')->index();
            $table->timestamps();

            $table->unique(['incident_id', 'version']);
        });

        Schema::create('timeline_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('incident_id')->nullable()->index();
            $table->uuid('device_id')->index();
            $table->string('source_type', 40)->index();
            $table->string('source_ref_id', 64)->index();
            $table->string('event_type', 80)->index();
            $table->timestamp('occurred_at')->index();
            $table->string('actor_user')->nullable()->index();
            $table->string('session_id', 64)->nullable()->index();
            $table->string('process_ref', 64)->nullable()->index();
            $table->string('parent_ref', 64)->nullable()->index();
            $table->decimal('risk_delta', 5, 2)->default(0);
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'source_type', 'source_ref_id'], 'timeline_events_tenant_source_unique');
        });

        Schema::create('anomaly_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('device_id')->index();
            $table->string('model_key', 64)->index();
            $table->string('detection_type', 32)->index();
            $table->decimal('anomaly_score', 6, 4);
            $table->decimal('confidence', 5, 2)->default(0);
            $table->string('severity', 16)->index();
            $table->string('status', 24)->default('open')->index();
            $table->json('evidence')->nullable();
            $table->timestamp('detected_at')->index();
            $table->timestamps();
        });

        Schema::create('feature_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('device_id')->index();
            $table->timestamp('window_start')->index();
            $table->timestamp('window_end')->index();
            $table->json('features');
            $table->json('labels')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'device_id', 'window_end'], 'feature_snapshots_tenant_device_window_idx');
        });

        Schema::create('ai_investigations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('incident_id')->nullable()->index();
            $table->uuid('device_id')->nullable()->index();
            $table->unsignedBigInteger('requested_by_user_id')->nullable()->index();
            $table->string('mode', 24)->default('explain');
            $table->string('model', 64)->nullable();
            $table->string('prompt_version', 24)->default('v1');
            $table->char('context_hash', 64)->nullable()->index();
            $table->json('token_usage')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_recommendations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('investigation_id')->index();
            $table->text('reasoning_summary')->nullable();
            $table->json('evidence')->nullable();
            $table->decimal('confidence_score', 5, 2)->default(0);
            $table->string('risk_level', 16)->default('low')->index();
            $table->json('recommended_actions')->nullable();
            $table->text('why_this_action')->nullable();
            $table->boolean('rollback_possible')->default(false);
            $table->boolean('approval_required')->default(false);
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedBigInteger('reviewed_by')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('remediation_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('source_type', 32)->index();
            $table->string('source_id', 64)->index();
            $table->string('risk_level', 16)->default('low')->index();
            $table->boolean('dry_run')->default(false);
            $table->boolean('simulation')->default(false);
            $table->boolean('requires_approval')->default(false);
            $table->string('status', 24)->default('draft')->index();
            $table->json('summary')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('remediation_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('plan_id')->index();
            $table->unsignedInteger('action_order')->default(1);
            $table->string('action_type', 48)->index();
            $table->uuid('target_device_id')->nullable()->index();
            $table->uuid('target_group_id')->nullable()->index();
            $table->json('args')->nullable();
            $table->json('guardrail_snapshot')->nullable();
            $table->boolean('approval_required')->default(false);
            $table->unsignedInteger('timeout_seconds')->default(300);
            $table->unsignedInteger('max_retries')->default(1);
            $table->unsignedInteger('cooldown_seconds')->default(0);
            $table->string('status', 24)->default('pending')->index();
            $table->timestamps();

            $table->unique(['plan_id', 'action_order']);
        });

        Schema::create('remediation_action_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('action_id')->index();
            $table->unsignedInteger('attempt_no')->default(1);
            $table->uuid('job_id')->nullable()->index();
            $table->uuid('job_run_id')->nullable()->index();
            $table->string('status', 24)->default('pending')->index();
            $table->integer('exit_code')->nullable();
            $table->json('evidence')->nullable();
            $table->text('error_text')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['action_id', 'attempt_no']);
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('request_type', 32)->index();
            $table->string('request_ref_id', 64)->index();
            $table->string('risk_level', 16)->default('low')->index();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable()->index();
            $table->string('required_role', 64)->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->unsignedBigInteger('decided_by')->nullable()->index();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
        });

        Schema::create('autonomy_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('scope_type', 16)->index();
            $table->string('scope_id', 64)->index();
            $table->string('autonomy_level', 16)->default('manual');
            $table->json('allowed_actions')->nullable();
            $table->json('blocked_conditions')->nullable();
            $table->json('maintenance_windows')->nullable();
            $table->unsignedInteger('max_parallel_actions')->default(1);
            $table->boolean('active')->default(true)->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('confidence_thresholds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('engine', 24)->index();
            $table->string('context_key', 64)->index();
            $table->decimal('min_confidence', 5, 2)->default(0);
            $table->decimal('approval_below', 5, 2)->default(0);
            $table->decimal('auto_execute_above', 5, 2)->default(100);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'engine', 'context_key', 'active'], 'confidence_thresholds_scope_unique');
        });

        Schema::create('action_guardrails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('action_type', 48)->index();
            $table->json('arg_schema')->nullable();
            $table->json('forbidden_patterns')->nullable();
            $table->json('allow_conditions')->nullable();
            $table->json('deny_conditions')->nullable();
            $table->unsignedInteger('max_targets')->default(1);
            $table->unsignedInteger('cooldown_seconds')->default(0);
            $table->boolean('requires_rollback_plan')->default(false);
            $table->boolean('active')->default(true)->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('action_rollbacks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('action_result_id')->index();
            $table->string('rollback_action_type', 48)->index();
            $table->json('rollback_args')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->json('result')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('feedback_outcomes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('source_type', 32)->index();
            $table->string('source_id', 64)->index();
            $table->string('feedback_type', 32)->index();
            $table->smallInteger('score')->nullable();
            $table->text('comment')->nullable();
            $table->unsignedBigInteger('provided_by_user_id')->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('learning_signals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('signal_type', 32)->index();
            $table->string('origin_ref_type', 32)->index();
            $table->string('origin_ref_id', 64)->index();
            $table->json('payload')->nullable();
            $table->json('proposed_change')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('operator_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('operator_user_id')->nullable()->index();
            $table->string('title', 160)->nullable();
            $table->json('scope')->nullable();
            $table->string('status', 24)->default('active')->index();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('assistant_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('conversation_id')->index();
            $table->string('mode', 24)->default('explain')->index();
            $table->char('context_hash', 64)->nullable()->index();
            $table->string('status', 24)->default('active')->index();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('session_id')->index();
            $table->string('role', 16)->index();
            $table->longText('content');
            $table->json('citations')->nullable();
            $table->json('token_usage')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('knowledge_artifacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('artifact_type', 32)->index();
            $table->string('title');
            $table->longText('body_markdown')->nullable();
            $table->json('tags')->nullable();
            $table->json('source_refs')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('incident_labels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('incident_id')->index();
            $table->string('label', 64)->index();
            $table->string('source', 24)->default('system')->index();
            $table->decimal('confidence', 5, 2)->default(0);
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('event_embeddings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('timeline_event_id')->index();
            $table->string('model', 64)->nullable();
            $table->json('embedding')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_embeddings');
        Schema::dropIfExists('incident_labels');
        Schema::dropIfExists('knowledge_artifacts');
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_sessions');
        Schema::dropIfExists('operator_conversations');
        Schema::dropIfExists('learning_signals');
        Schema::dropIfExists('feedback_outcomes');
        Schema::dropIfExists('action_rollbacks');
        Schema::dropIfExists('action_guardrails');
        Schema::dropIfExists('confidence_thresholds');
        Schema::dropIfExists('autonomy_policies');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('remediation_action_results');
        Schema::dropIfExists('remediation_actions');
        Schema::dropIfExists('remediation_plans');
        Schema::dropIfExists('ai_recommendations');
        Schema::dropIfExists('ai_investigations');
        Schema::dropIfExists('feature_snapshots');
        Schema::dropIfExists('anomaly_findings');
        Schema::dropIfExists('timeline_events');
        Schema::dropIfExists('incident_timelines');
        Schema::dropIfExists('correlated_incidents');
        Schema::dropIfExists('threat_findings');
        Schema::dropIfExists('device_risk_scores');
        Schema::dropIfExists('device_health_scores');
        Schema::dropIfExists('device_health_snapshots');
    }
};
