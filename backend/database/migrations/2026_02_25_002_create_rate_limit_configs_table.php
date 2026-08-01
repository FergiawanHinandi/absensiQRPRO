<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create rate_limit_configs table
 *
 * Allows per-school dynamic rate limit configuration.
 * Spec: critical-rate-limiting / tasks.md Task 11.2
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rate_limit_configs')) {
            return;
        }

        Schema::create('rate_limit_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->nullable()->index();  // null = global
            $table->string('endpoint_key', 100)->index();                  // e.g. 'login'
            $table->integer('max_attempts');
            $table->integer('decay_seconds');
            $table->boolean('is_active')->default(true);
            $table->string('reason', 500)->nullable();   // Why was this custom limit set?
            $table->unsignedBigInteger('set_by')->nullable();  // user_id who set it
            $table->timestamps();

            // Unique: one config per school per endpoint
            $table->unique(['school_id', 'endpoint_key'], 'uq_school_endpoint_rl');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_limit_configs');
    }
};
