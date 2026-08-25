<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_catalog_categories', function (Blueprint $table) {
            $table->json('applies_to')->nullable()->after('description');
        });

        Schema::table('service_catalog_categories', function (Blueprint $table) {
            $table->json('applies_to')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('product_catalog_categories', function (Blueprint $table) {
            $table->dropColumn('applies_to');
        });

        Schema::table('service_catalog_categories', function (Blueprint $table) {
            $table->dropColumn('applies_to');
        });
    }
};
