<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('garage_booking_id')->constrained('garage_bookings')->cascadeOnDelete();
            $table->foreignId('garage_id')->constrained('garages')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('technicians')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('garage_services')->nullOnDelete();
            $table->string('vehicle_reg')->nullable();
            $table->string('status')->default('open');
            $table->text('diagnosis')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('labour_total', 12, 2)->nullable();
            $table->decimal('parts_total', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique('garage_booking_id');
            $table->index(['garage_id', 'status']);
            $table->index('vehicle_reg');
        });

        Schema::create('work_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->string('item_type')->default('labour'); // labour|part|other
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('service_history', function (Blueprint $table) {
            $table->id();
            $table->string('vehicle_reg')->nullable();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('garage_id')->nullable()->constrained('garages')->nullOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->foreignId('garage_booking_id')->nullable()->constrained('garage_bookings')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('service_name')->nullable();
            $table->text('summary')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->timestamp('serviced_at')->nullable();
            $table->timestamps();

            $table->index('vehicle_reg');
            $table->index(['vehicle_id', 'serviced_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['user_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('service_history');
        Schema::dropIfExists('work_order_items');
        Schema::dropIfExists('work_orders');
    }
};
