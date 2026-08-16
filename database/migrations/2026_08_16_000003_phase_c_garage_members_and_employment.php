<?php

use App\Models\Garage;
use App\Models\Technician;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('garage_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('garage_id')->constrained('garages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('membership_type'); // owner|manager|technician|receptionist|accountant
            $table->string('status')->default('active');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['garage_id', 'user_id', 'membership_type'], 'garage_members_unique_type');
            $table->index(['user_id', 'status']);
        });

        Schema::create('employment_relationships', function (Blueprint $table) {
            $table->id();
            $table->string('employer_type'); // transport_owner|garage
            $table->unsignedBigInteger('employer_id');
            $table->foreignId('employee_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('employment_type'); // driver|technician|staff
            $table->string('position')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['employer_type', 'employer_id']);
            $table->index(['employee_user_id', 'status']);
        });

        // Independent drivers may exist before a fleet hires them.
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
        });

        DB::statement('ALTER TABLE drivers MODIFY owner_id BIGINT UNSIGNED NULL');

        Schema::table('drivers', function (Blueprint $table) {
            $table->foreign('owner_id')->references('id')->on('transport_owners')->nullOnDelete();
        });

        // Backfill garage members from owners + technicians.
        Garage::query()->select('id', 'owner_id', 'created_at')->orderBy('id')->chunkById(100, function ($garages) {
            foreach ($garages as $garage) {
                DB::table('garage_members')->insertOrIgnore([
                    'garage_id' => $garage->id,
                    'user_id' => $garage->owner_id,
                    'membership_type' => 'owner',
                    'status' => 'active',
                    'joined_at' => $garage->created_at ?? now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Technician::query()->select('id', 'garage_id', 'user_id', 'status', 'created_at')->orderBy('id')->chunkById(100, function ($techs) {
            foreach ($techs as $tech) {
                DB::table('garage_members')->insertOrIgnore([
                    'garage_id' => $tech->garage_id,
                    'user_id' => $tech->user_id,
                    'membership_type' => 'technician',
                    'status' => $tech->status === 'active' ? 'active' : 'inactive',
                    'joined_at' => $tech->created_at ?? now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('employment_relationships')->insert([
                    'employer_type' => 'garage',
                    'employer_id' => $tech->garage_id,
                    'employee_user_id' => $tech->user_id,
                    'employment_type' => 'technician',
                    'position' => 'technician',
                    'start_date' => optional($tech->created_at)->toDateString() ?? now()->toDateString(),
                    'status' => $tech->status === 'active' ? 'active' : 'ended',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        // Backfill driver employments where owner_id is set.
        DB::table('drivers')->whereNotNull('owner_id')->orderBy('id')->chunkById(100, function ($drivers) {
            foreach ($drivers as $driver) {
                DB::table('employment_relationships')->insert([
                    'employer_type' => 'transport_owner',
                    'employer_id' => $driver->owner_id,
                    'employee_user_id' => $driver->user_id,
                    'employment_type' => 'driver',
                    'position' => 'driver',
                    'start_date' => $driver->created_at ? substr((string) $driver->created_at, 0, 10) : now()->toDateString(),
                    'status' => $driver->status === 'active' ? 'active' : 'ended',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_relationships');
        Schema::dropIfExists('garage_members');

        Schema::table('drivers', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
        });

        // Re-attach orphaned drivers to avoid NOT NULL failure (best-effort).
        DB::table('drivers')->whereNull('owner_id')->delete();

        DB::statement('ALTER TABLE drivers MODIFY owner_id BIGINT UNSIGNED NOT NULL');

        Schema::table('drivers', function (Blueprint $table) {
            $table->foreign('owner_id')->references('id')->on('transport_owners')->cascadeOnDelete();
        });
    }
};
