<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action'); // e.g., 'login_failed', 'scan_attendance', 'update_attendance'
            $table->string('model_type')->nullable();
            $table->unsignedBigInteger('model_id')->nullable(); 
            // OR use $table->nullableMorphs('model'); but manual definition is safer for exact user req
            
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->json('payload')->nullable(); // Extra data (old values, new values)
            $table->timestamps();

            // Index for faster queries
            $table->index(['model_type', 'model_id']);
            $table->index('user_id');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
