<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remote_support_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('device_id')->index();
            $table->unsignedBigInteger('requested_by')->nullable()->index();
            $table->string('status')->default('active')->index();
            $table->timestamp('last_capture_requested_at')->nullable();
            $table->timestamp('last_frame_received_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_support_sessions');
    }
};
