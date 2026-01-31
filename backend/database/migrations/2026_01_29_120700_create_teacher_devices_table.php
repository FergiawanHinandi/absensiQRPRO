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
        Schema::create('teacher_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->string('device_id'); // Unique device identifier
            $table->string('device_name')->nullable(); // e.g., 'iPhone 13', 'Samsung S21'
            $table->string('platform')->nullable(); // 'android', 'ios'
            $table->string('device_model')->nullable(); // Device model info
            $table->boolean('is_approved')->default(false); // Admin must approve
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            // Unique constraint: one device per teacher
            $table->unique(['teacher_id', 'device_id']);
            
            // Unique constraint: device can only belong to one teacher per school
            $table->unique(['school_id', 'device_id'], 'teacher_devices_school_device_unique');
            
            // Index for quick lookups
            $table->index(['school_id', 'teacher_id', 'is_approved']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teacher_devices');
    }
};
