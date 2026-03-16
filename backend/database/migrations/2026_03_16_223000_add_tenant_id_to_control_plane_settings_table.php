<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('control_plane_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('control_plane_settings', 'tenant_id')) {
                $table->uuid('tenant_id')->nullable()->index()->after('key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('control_plane_settings', function (Blueprint $table) {
            if (Schema::hasColumn('control_plane_settings', 'tenant_id')) {
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            }
        });
    }
};
