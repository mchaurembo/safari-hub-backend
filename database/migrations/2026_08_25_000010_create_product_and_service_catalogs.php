<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_catalog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_catalog_category_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('default_unit', 32)->default('pcs');
            $table->string('sku_hint', 64)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_catalog_category_id', 'is_active'], 'prod_catalog_item_cat_idx');
        });

        Schema::create('service_catalog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('service_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_catalog_category_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('default_pricing_type', 16)->default('fixed');
            $table->unsignedSmallInteger('default_duration_minutes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['service_catalog_category_id', 'is_active'], 'svc_catalog_item_cat_idx');
        });

        Schema::table('business_products', function (Blueprint $table) {
            $table->foreignId('product_catalog_item_id')
                ->nullable()
                ->after('business_product_category_id')
                ->constrained('product_catalog_items')
                ->nullOnDelete();
        });

        Schema::table('garage_services', function (Blueprint $table) {
            $table->foreignId('service_catalog_item_id')
                ->nullable()
                ->after('name')
                ->constrained('service_catalog_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('garage_services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_catalog_item_id');
        });

        Schema::table('business_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_catalog_item_id');
        });

        Schema::dropIfExists('service_catalog_items');
        Schema::dropIfExists('service_catalog_categories');
        Schema::dropIfExists('product_catalog_items');
        Schema::dropIfExists('product_catalog_categories');
        Schema::dropIfExists('product_units');
    }
};
