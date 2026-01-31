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
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('device_id');
            $table->string('device_name')->nullable(); // e.g., 'iPhone 13', 'Samsung S21'
            $table->string('platform')->nullable(); // e.g., 'android', 'ios', 'web'
            $table->timestamp('last_used_at')->useCurrent();
            $table->boolean('is_trusted')->default(true);
            $table->timestamps();

            // Unique constraint on user_id + device_id
            $table->unique(['user_id', 'device_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
    }
};
