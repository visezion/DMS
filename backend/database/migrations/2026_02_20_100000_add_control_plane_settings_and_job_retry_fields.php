<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('job_runs', function (Blueprint $table) {
            $table->unsignedInteger('attempt_count')->default(0)->after('status');
            $table->timestamp('next_retry_at')->nullable()->after('finished_at');
            $table->text('last_error')->nullable()->after('result_payload');
        });

        Schema::create('control_plane_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_plane_settings');

        Schema::table('job_runs', function (Blueprint $table) {
            $table->dropColumn(['attempt_count', 'next_retry_at', 'last_error']);
        });
    }
};
