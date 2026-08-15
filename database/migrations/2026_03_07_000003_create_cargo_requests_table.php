<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cargo_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();

            // Pickup
            $table->decimal('pickup_lat', 10, 7);
            $table->decimal('pickup_lng', 10, 7);
            $table->string('pickup_address');

            // Destination
            $table->decimal('dest_lat', 10, 7);
            $table->decimal('dest_lng', 10, 7);
            $table->string('dest_address');

            $table->decimal('distance_km', 8, 2);

            // Cargo details
            $table->string('cargo_description');
            $table->decimal('weight_kg', 8, 2)->nullable();

            // Pricing negotiation
            $table->decimal('quoted_price', 10, 2)->nullable();
            $table->decimal('customer_budget', 10, 2)->nullable();

            // Status flow: pending → quoted → accepted → in_progress → delivered → completed | declined | cancelled
            $table->enum('status', [
                'pending',
                'quoted',
                'accepted',
                'declined',
                'in_progress',
                'delivered',
                'completed',
                'cancelled',
            ])->default('pending');

            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cargo_requests');
    }
};
