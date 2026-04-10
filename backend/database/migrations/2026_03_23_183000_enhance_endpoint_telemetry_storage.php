<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('device_health_snapshots', function (Blueprint $table) {
            $table->json('raw_payload')->nullable()->after('metrics');
        });

        Schema::table('device_behavior_logs', function (Blueprint $table) {
            $table->string('event_uid', 128)->nullable()->after('file_path');
            $table->string('session_uid', 128)->nullable()->after('event_uid');
            $table->string('process_uid', 128)->nullable()->after('session_uid');
            $table->string('parent_process_uid', 128)->nullable()->after('process_uid');
            $table->string('checkin_id', 128)->nullable()->after('parent_process_uid');

            $table->index(['device_id', 'event_uid'], 'device_behavior_device_event_uid_idx');
            $table->index(['device_id', 'session_uid', 'occurred_at'], 'device_behavior_device_session_time_idx');
            $table->index(['device_id', 'checkin_id', 'occurred_at'], 'device_behavior_device_checkin_time_idx');
            $table->unique(['device_id', 'event_uid'], 'device_behavior_device_event_uid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('device_behavior_logs', function (Blueprint $table) {
            $table->dropUnique('device_behavior_device_event_uid_unique');
            $table->dropIndex('device_behavior_device_event_uid_idx');
            $table->dropIndex('device_behavior_device_session_time_idx');
            $table->dropIndex('device_behavior_device_checkin_time_idx');

            $table->dropColumn([
                'event_uid',
                'session_uid',
                'process_uid',
                'parent_process_uid',
                'checkin_id',
            ]);
        });

        Schema::table('device_health_snapshots', function (Blueprint $table) {
            $table->dropColumn('raw_payload');
        });
    }
};
