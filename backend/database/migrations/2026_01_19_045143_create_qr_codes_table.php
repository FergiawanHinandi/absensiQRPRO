<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->onDelete('cascade');
            $table->foreignId('schedule_id')->constrained('schedules')->onDelete('cascade');
            $table->string('token', 255)->unique()->comment('Encrypted QR token');
            $table->enum('qr_type', ['in', 'out'])->default('in');
            $table->string('qr_image_url', 255)->nullable();
            $table->foreignId('generated_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('valid_from');
            $table->timestamp('valid_until');
            $table->integer('max_scans')->nullable()->comment('NULL = unlimited');
            $table->integer('scan_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('location_required')->default(true);
            $table->json('metadata')->nullable()->comment('Extra config');
            $table->timestamps();

            // Indexes
            $table->index('token', 'idx_qr_codes_token');
            $table->index(['schedule_id', 'valid_until'], 'idx_qr_codes_schedule');
            $table->index(['is_active', 'valid_until'], 'idx_qr_codes_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qr_codes');
    }
};
