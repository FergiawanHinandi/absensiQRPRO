<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create rate_limit_violations table
 *
 * Spec: critical-rate-limiting / tasks.md Task 11.1
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rate_limit_violations')) {
            return;
        }

        Schema::create('rate_limit_violations', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint_key', 100)->index();        // e.g. 'login', 'qr_scan'
            $table->string('rate_limit_key', 500);               // Full Redis key
            $table->string('ip_address', 45)->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('school_id')->nullable()->index();
            $table->integer('attempt_count')->default(0);
            $table->integer('max_attempts')->default(0);
            $table->text('user_agent')->nullable();
            $table->string('url', 500)->nullable();
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_limit_violations');
    }
};
