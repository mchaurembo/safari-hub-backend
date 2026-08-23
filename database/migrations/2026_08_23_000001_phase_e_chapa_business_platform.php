<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('business_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_category_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('default_capability_codes')->nullable();
            $table->json('onboarding_template')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_category_id', 'code']);
        });

        Schema::create('business_capabilities', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('module_key')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('legal_name');
            $table->string('trade_name')->nullable();
            $table->string('slug')->unique();
            $table->foreignId('business_category_id')->constrained();
            $table->foreignId('business_type_id')->constrained();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('draft');
            $table->string('verification_status')->default('unverified');
            $table->string('tax_id')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('cover_url')->nullable();
            $table->string('timezone')->default('Africa/Dar_es_Salaam');
            $table->string('currency', 3)->default('TZS');
            $table->json('settings')->nullable();
            $table->unsignedBigInteger('legacy_transport_owner_id')->nullable()->unique();
            $table->unsignedBigInteger('legacy_garage_id')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('business_type_id');
        });

        Schema::create('business_profiles', function (Blueprint $table) {
            $table->foreignId('business_id')->primary()->constrained()->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('country')->default('TZ');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('operating_hours')->nullable();
            $table->json('social_links')->nullable();
            $table->timestamps();
        });

        Schema::create('business_branches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->boolean('is_head_office')->default(false);
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('country')->default('TZ');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->default('active');
            $table->json('operating_hours')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('business_capability_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_capability_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->json('config')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'business_capability_id'], 'biz_cap_assign_unique');
        });

        Schema::create('membership_roles', function (Blueprint $table) {
            $table->id();
            $table->string('scope');
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(true);
            $table->timestamps();

            $table->unique(['scope', 'code']);
        });

        Schema::create('membership_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['membership_role_id', 'permission_id'], 'mem_role_perm_unique');
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('business_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['business_type_id', 'code']);
            $table->index(['business_id', 'code']);
        });

        Schema::create('position_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['position_id', 'permission_id']);
        });

        Schema::create('business_memberships', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_role_id')->constrained();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('active');
            $table->foreignId('invited_by_membership_id')->nullable()->constrained('business_memberships')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->foreignId('default_branch_id')->nullable()->constrained('business_branches')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'business_id']);
            $table->index(['business_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('business_membership_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_branch_id')->constrained()->cascadeOnDelete();
            $table->string('access_level')->default('operate');
            $table->timestamps();

            $table->unique(['business_membership_id', 'business_branch_id'], 'biz_mem_branch_unique');
        });

        Schema::create('business_membership_invitations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('token_hash');
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->foreignId('membership_role_id')->constrained();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->json('branch_ids')->nullable();
            $table->foreignId('invited_by_membership_id')->nullable()->constrained('business_memberships')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index('token_hash');
        });

        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('preferred_language')->default('sw');
            $table->json('notification_preferences')->nullable();
            $table->timestamps();
        });

        Schema::create('business_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('customer_number')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('first_interaction_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'user_id']);
            $table->unique(['business_id', 'customer_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_customers');
        Schema::dropIfExists('customer_profiles');
        Schema::dropIfExists('business_membership_invitations');
        Schema::dropIfExists('business_membership_branches');
        Schema::dropIfExists('business_memberships');
        Schema::dropIfExists('position_permissions');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('membership_role_permissions');
        Schema::dropIfExists('membership_roles');
        Schema::dropIfExists('business_capability_assignments');
        Schema::dropIfExists('business_branches');
        Schema::dropIfExists('business_profiles');
        Schema::dropIfExists('businesses');
        Schema::dropIfExists('business_capabilities');
        Schema::dropIfExists('business_types');
        Schema::dropIfExists('business_categories');
    }
};
