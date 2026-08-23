<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'code'], 'biz_prod_cat_code_unique');
            $table->index(['business_id', 'is_active'], 'biz_prod_cat_active_idx');
        });

        Schema::create('business_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_product_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 3)->default('TZS');
            $table->decimal('stock_quantity', 12, 2)->default(0);
            $table->string('unit', 32)->default('pcs');
            $table->string('status', 32)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'status'], 'biz_prod_status_idx');
            $table->unique(['business_id', 'sku'], 'biz_prod_sku_unique');
        });

        Schema::create('business_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('order_number')->nullable();
            $table->string('status', 32)->default('pending');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 3)->default('TZS');
            $table->text('notes')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'status'], 'biz_order_status_idx');
            $table->unique(['business_id', 'order_number'], 'biz_order_num_unique');
        });

        Schema::create('business_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->timestamps();

            $table->index('business_order_id', 'biz_order_item_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_order_items');
        Schema::dropIfExists('business_orders');
        Schema::dropIfExists('business_products');
        Schema::dropIfExists('business_product_categories');
    }
};
