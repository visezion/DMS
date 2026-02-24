<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active');
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable()->after('id')->index();
            $table->boolean('is_active')->default(true)->after('password');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->uuid('role_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['role_id', 'user_id']);
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->uuid('permission_id');
            $table->uuid('role_id');
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('hostname');
            $table->string('os_name');
            $table->string('os_version')->nullable();
            $table->string('agent_version');
            $table->string('status')->default('pending');
            $table->timestamp('last_seen_at')->nullable();
            $table->string('serial_number')->nullable()->index();
            $table->string('azure_ad_device_id')->nullable()->index();
            $table->string('meshcentral_device_id')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();
        });

        Schema::create('device_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('device_id')->index();
            $table->string('identity_type');
            $table->string('subject_dn')->nullable();
            $table->string('issuer_dn')->nullable();
            $table->string('cert_thumbprint_sha256', 64)->nullable()->index();
            $table->longText('public_key_pem')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->boolean('revoked')->default(false);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('key_materials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('kid')->unique();
            $table->string('purpose');
            $table->string('alg');
            $table->string('status')->default('active');
            $table->timestamp('not_before');
            $table->timestamp('not_after');
            $table->string('public_fingerprint_sha256', 64);
            $table->longText('public_key_pem');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('device_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('dynamic_rule')->nullable();
            $table->timestamps();
        });

        Schema::create('device_group_memberships', function (Blueprint $table) {
            $table->uuid('device_group_id');
            $table->uuid('device_id');
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['device_group_id', 'device_id']);
        });

        Schema::create('packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('slug');
            $table->string('publisher')->nullable();
            $table->string('package_type');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('package_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('package_id')->index();
            $table->string('version');
            $table->string('channel')->default('stable');
            $table->json('install_args')->nullable();
            $table->json('uninstall_args')->nullable();
            $table->json('detection_rules');
            $table->boolean('is_deprecated')->default(false);
            $table->timestamps();
            $table->unique(['package_id', 'version', 'channel']);
        });

        Schema::create('package_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('package_version_id')->index();
            $table->string('file_name');
            $table->string('source_uri');
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64)->index();
            $table->string('signature_type')->nullable();
            $table->json('signature_metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('install_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('name');
            $table->json('defaults')->nullable();
            $table->timestamps();
        });

        Schema::create('policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('slug');
            $table->string('category');
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('policy_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('policy_id')->index();
            $table->unsignedInteger('version_number');
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['policy_id', 'version_number']);
        });

        Schema::create('policy_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('policy_version_id')->index();
            $table->unsignedInteger('order_index')->default(0);
            $table->string('rule_type');
            $table->json('rule_config');
            $table->boolean('enforce')->default(true);
            $table->timestamps();
        });

        Schema::create('policy_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('policy_version_id')->index();
            $table->string('target_type');
            $table->uuid('target_id')->index();
            $table->string('rollout_strategy')->default('immediate');
            $table->json('schedule_window')->nullable();
            $table->timestamps();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('job_type');
            $table->string('status')->default('queued');
            $table->unsignedInteger('priority')->default(100);
            $table->json('payload');
            $table->string('target_type');
            $table->uuid('target_id')->index();
            $table->timestamp('not_before')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('job_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('job_id')->index();
            $table->uuid('device_id')->index();
            $table->string('status')->default('pending');
            $table->timestamp('acked_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('exit_code')->nullable();
            $table->json('result_payload')->nullable();
            $table->timestamps();
            $table->unique(['job_id', 'device_id']);
        });

        Schema::create('job_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('job_run_id')->index();
            $table->string('event_type');
            $table->json('event_payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('compliance_checks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('check_type');
            $table->json('definition');
            $table->timestamps();
        });

        Schema::create('compliance_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('compliance_check_id')->index();
            $table->uuid('device_id')->index();
            $table->string('status');
            $table->json('details')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
            $table->index(['device_id', 'checked_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->uuid('actor_device_id')->nullable()->index();
            $table->string('action');
            $table->string('entity_type');
            $table->string('entity_id');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('request_id')->nullable()->index();
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('prev_hash', 64)->nullable();
            $table->string('row_hash', 64)->unique();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('enrollment_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->uuid('used_by_device_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_tokens');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('compliance_results');
        Schema::dropIfExists('compliance_checks');
        Schema::dropIfExists('job_events');
        Schema::dropIfExists('job_runs');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('policy_assignments');
        Schema::dropIfExists('policy_rules');
        Schema::dropIfExists('policy_versions');
        Schema::dropIfExists('policies');
        Schema::dropIfExists('install_profiles');
        Schema::dropIfExists('package_files');
        Schema::dropIfExists('package_versions');
        Schema::dropIfExists('packages');
        Schema::dropIfExists('device_group_memberships');
        Schema::dropIfExists('device_groups');
        Schema::dropIfExists('key_materials');
        Schema::dropIfExists('device_identities');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['tenant_id', 'is_active']);
        });

        Schema::dropIfExists('tenants');
    }
};
