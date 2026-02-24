<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agent_releases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('version');
            $table->string('platform')->default('windows-x64');
            $table->string('file_name');
            $table->string('storage_path');
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('notes')->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_releases');
    }
};
