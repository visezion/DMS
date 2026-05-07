<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autonomous_action_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('action_key', 80)->index();
            $table->string('display_name', 120);
            $table->text('description')->nullable();
            $table->json('supported_target_types')->nullable();
            $table->json('required_parameters')->nullable();
            $table->string('safety_class', 24)->default('moderate')->index();
            $table->boolean('reversible')->default(false);
            $table->string('rollback_handler', 120)->nullable();
            $table->string('recommended_approval_mode', 24)->default('approval_required')->index();
            $table->unsignedInteger('cooldown_minutes')->default(15);
            $table->boolean('requires_online')->default(true);
            $table->boolean('supports_offline')->default(false);
            $table->boolean('tenant_compatible')->default(true);
            $table->string('execution_strategy', 24)->default('job')->index();
            $table->json('default_payload')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'action_key'], 'autonomous_action_definitions_scope_key_unique');
        });

        Schema::create('autonomous_response_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('name', 160);
            $table->string('scope_type', 32)->index();
            $table->string('scope_id', 80)->nullable()->index();
            $table->string('trigger_type', 80)->default('any')->index();
            $table->decimal('minimum_risk_score', 5, 2)->default(0);
            $table->json('allowed_actions')->nullable();
            $table->json('blocked_actions')->nullable();
            $table->string('autonomy_mode', 24)->default('recommend_only')->index();
            $table->decimal('minimum_confidence', 6, 2)->default(0);
            $table->boolean('requires_rollback_plan')->default(false);
            $table->unsignedInteger('max_actions_per_hour')->default(4);
            $table->unsignedInteger('cooldown_minutes')->default(30);
            $table->boolean('enabled')->default(true)->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();

            $table->index(['tenant_id', 'scope_type', 'scope_id', 'trigger_type'], 'autonomous_response_policies_resolution_idx');
        });

        Schema::create('risk_action_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('name', 160);
            $table->string('trigger_type', 80)->index();
            $table->string('minimum_severity', 16)->nullable()->index();
            $table->string('maximum_severity', 16)->nullable()->index();
            $table->decimal('minimum_risk_score', 5, 2)->default(0);
            $table->decimal('maximum_risk_score', 5, 2)->nullable();
            $table->json('candidate_actions');
            $table->json('preconditions')->nullable();
            $table->json('rollback_metadata')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->unsignedInteger('priority')->default(100)->index();
            $table->timestamps();

            $table->index(['tenant_id', 'trigger_type', 'enabled', 'priority'], 'risk_action_mappings_resolution_idx');
        });

        Schema::create('autonomous_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('device_id')->nullable()->index();
            $table->uuid('incident_id')->nullable()->index();
            $table->uuid('finding_id')->nullable()->index();
            $table->uuid('policy_id')->nullable()->index();
            $table->string('trigger_source', 80)->index();
            $table->json('input_context')->nullable();
            $table->string('recommended_action', 80)->nullable()->index();
            $table->json('recommended_payload')->nullable();
            $table->json('alternative_actions')->nullable();
            $table->decimal('confidence_score', 6, 2)->default(0)->index();
            $table->text('rationale')->nullable();
            $table->json('explanation')->nullable();
            $table->string('decision_mode', 24)->default('recommend_only')->index();
            $table->string('status', 32)->default('generated')->index();
            $table->boolean('simulation')->default(false)->index();
            $table->boolean('dry_run')->default(false);
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->string('execution_reference', 80)->nullable()->index();
            $table->string('rollback_reference', 80)->nullable()->index();
            $table->string('approval_request_id', 80)->nullable()->index();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'decision_mode', 'created_at'], 'autonomous_decisions_dashboard_idx');
        });

        Schema::create('autonomous_execution_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('decision_id')->index();
            $table->string('action_name', 80)->index();
            $table->string('target_type', 32)->index();
            $table->string('target_id', 80)->nullable()->index();
            $table->string('execution_status', 32)->default('pending')->index();
            $table->json('command_payload')->nullable();
            $table->longText('output_log')->nullable();
            $table->boolean('rollback_available')->default(false);
            $table->string('rollback_status', 32)->nullable()->index();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('confidence_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('decision_id')->index();
            $table->string('factor_name', 80)->index();
            $table->decimal('factor_weight', 8, 4)->default(0);
            $table->decimal('factor_value', 8, 2)->default(0);
            $table->json('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('confidence_evidence');
        Schema::dropIfExists('autonomous_execution_results');
        Schema::dropIfExists('autonomous_decisions');
        Schema::dropIfExists('risk_action_mappings');
        Schema::dropIfExists('autonomous_response_policies');
        Schema::dropIfExists('autonomous_action_definitions');
    }
};
