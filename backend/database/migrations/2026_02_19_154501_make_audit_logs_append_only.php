<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared('CREATE TRIGGER trg_audit_logs_immutable BEFORE UPDATE ON audit_logs BEGIN SELECT RAISE(ABORT, "audit_logs is append-only"); END;');
            DB::unprepared('CREATE TRIGGER trg_audit_logs_immutable_delete BEFORE DELETE ON audit_logs BEGIN SELECT RAISE(ABORT, "audit_logs is append-only"); END;');
            return;
        }

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION prevent_audit_logs_mutation()
RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION 'audit_logs is append-only';
END;
$$ LANGUAGE plpgsql;
SQL);

            DB::unprepared('CREATE TRIGGER trg_audit_logs_immutable BEFORE UPDATE ON audit_logs FOR EACH ROW EXECUTE FUNCTION prevent_audit_logs_mutation();');
            DB::unprepared('CREATE TRIGGER trg_audit_logs_immutable_delete BEFORE DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION prevent_audit_logs_mutation();');
            return;
        }

        if ($driver === 'mysql') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_audit_logs_immutable
BEFORE UPDATE ON audit_logs
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_audit_logs_immutable_delete
BEFORE DELETE ON audit_logs
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_logs_immutable;');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_logs_immutable_delete;');

        if ($driver === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_audit_logs_mutation;');
        }
    }
};
