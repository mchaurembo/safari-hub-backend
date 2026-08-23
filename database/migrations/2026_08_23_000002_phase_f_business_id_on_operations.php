<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'transport_owners',
        'garages',
        'vehicles',
        'drivers',
        'trips',
        'garage_services',
        'garage_bookings',
        'work_orders',
        'technicians',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'business_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->foreignId('business_id')->nullable()->after('id')->constrained()->nullOnDelete();
                $blueprint->index('business_id', substr($table, 0, 10).'_biz_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'business_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('business_id');
            });
        }
    }
};
