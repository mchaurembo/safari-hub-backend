<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_roles', function (Blueprint $table) {
            $table->string('status')->default('active')->after('role_id');
            $table->string('verification_status')->nullable()->after('status');
            $table->timestamp('started_at')->nullable()->after('verification_status');
            $table->timestamp('ended_at')->nullable()->after('started_at');
            $table->foreignId('approved_by')->nullable()->after('ended_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });

        // Existing pivot rows become active capabilities.
        DB::table('user_roles')->whereNull('started_at')->update([
            'status' => 'active',
            'started_at' => now(),
        ]);

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('module')->nullable();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');

        Schema::table('user_roles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn([
                'status',
                'verification_status',
                'started_at',
                'ended_at',
                'approved_at',
            ]);
        });
    }
};
