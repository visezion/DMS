<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasMfaEnabled = Schema::hasColumn('users', 'mfa_enabled');
        $hasMfaSecret = Schema::hasColumn('users', 'mfa_secret');
        if ($hasMfaEnabled && $hasMfaSecret) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'mfa_enabled')) {
                $table->boolean('mfa_enabled')->default(false)->after('is_active');
            }
            if (! Schema::hasColumn('users', 'mfa_secret')) {
                $table->text('mfa_secret')->nullable()->after('mfa_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $toDrop = [];
            if (Schema::hasColumn('users', 'mfa_enabled')) {
                $toDrop[] = 'mfa_enabled';
            }
            if (Schema::hasColumn('users', 'mfa_secret')) {
                $toDrop[] = 'mfa_secret';
            }
            if ($toDrop !== []) {
                $table->dropColumn($toDrop);
            }
        });
    }
};
