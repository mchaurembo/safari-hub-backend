<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enforce unique email and unique phone at the database level (nullable phone allows multiple NULLs).
     */
    public function up(): void
    {
        if (! Schema::hasIndex('users', ['email'], 'unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unique('email');
            });
        }

        if (! Schema::hasIndex('users', ['phone'], 'unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unique('phone');
            });
        }
    }

    public function down(): void
    {
        // Do not drop: email/phone unique indexes are required; they may come from
        // 0001_01_01_000000 (email) or 2026_03_19_000000 (phone).
    }
};
