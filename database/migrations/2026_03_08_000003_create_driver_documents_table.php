<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->onDelete('cascade');
            $table->enum('document_type', [
                'driving_license',
                'psv_license',
                'national_id',
                'passport',
                'medical_certificate',
                'police_clearance',
                'other',
            ]);
            $table->string('label')->nullable();      // custom label for "other"
            $table->string('file_path');              // relative path in storage
            $table->string('original_name');          // original filename
            $table->date('expiry_date')->nullable();
            $table->enum('verified', ['pending', 'verified', 'rejected'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_documents');
    }
};
