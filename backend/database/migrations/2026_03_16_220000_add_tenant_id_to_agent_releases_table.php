<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('agent_releases', function (Blueprint $table) {
            if (! Schema::hasColumn('agent_releases', 'tenant_id')) {
                $table->uuid('tenant_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('agent_releases', function (Blueprint $table) {
            if (Schema::hasColumn('agent_releases', 'tenant_id')) {
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            }
        });
    }
};
