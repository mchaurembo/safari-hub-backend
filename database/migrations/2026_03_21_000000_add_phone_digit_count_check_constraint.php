<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add CHECK constraint: phone and whatsapp_number must be 10-13 digits (or NULL).
     * Enforces at DB level what the application validation does.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            // MySQL 8.0+ REGEXP_REPLACE; MariaDB 10.0.5+ also supports it
            $digitExpr = "LENGTH(REGEXP_REPLACE(phone, '[^0-9]', ''))";
            DB::statement("ALTER TABLE users ADD CONSTRAINT chk_phone_10_to_13_digits CHECK (phone IS NULL OR ({$digitExpr} BETWEEN 10 AND 13))");

            $waDigitExpr = "LENGTH(REGEXP_REPLACE(whatsapp_number, '[^0-9]', ''))";
            DB::statement("ALTER TABLE users ADD CONSTRAINT chk_whatsapp_10_to_13_digits CHECK (whatsapp_number IS NULL OR ({$waDigitExpr} BETWEEN 10 AND 13))");
        }
        // SQLite (testing): no REGEXP_REPLACE; CHECK constraints would require table rebuild. Rely on app validation.
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_phone_10_to_13_digits');
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_whatsapp_10_to_13_digits');
        }
    }
};
