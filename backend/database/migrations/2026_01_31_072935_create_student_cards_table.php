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
        Schema::create('student_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('school_id')->constrained('schools')->onDelete('cascade');
            $table->string('qr_hash')->index();
            $table->timestamp('issued_at');
            $table->foreignId('issued_by')->constrained('users'); // Admin ID
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            // Ensure only one active card per student if desired, or just index
            // $table->unique(['student_id', 'is_active']); // Optional, but let's keep it flexible
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_cards');
    }
};
