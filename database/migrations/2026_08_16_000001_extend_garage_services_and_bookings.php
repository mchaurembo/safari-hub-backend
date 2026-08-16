<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('garage_services', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->unsignedInteger('duration_minutes')->nullable()->after('type');
            $table->string('status')->default('active')->after('duration_minutes');
        });

        Schema::table('garage_bookings', function (Blueprint $table) {
            $table->string('vehicle_reg')->nullable()->after('technician_id');
            $table->text('notes')->nullable()->after('vehicle_reg');
            $table->decimal('amount', 12, 2)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('garage_services', function (Blueprint $table) {
            $table->dropColumn(['description', 'duration_minutes', 'status']);
        });

        Schema::table('garage_bookings', function (Blueprint $table) {
            $table->dropColumn(['vehicle_reg', 'notes', 'amount']);
        });
    }
};
