<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * FAIL-SECURE: Database-based rate limit fallback table
     * Used when Redis is unavailable to maintain rate limiting
     */
    public function up(): void
    {
        Schema::create('rate_limit_fallback', function (Blueprint $table) {
            $table->id();

            // Identifier for rate limit (can be user_id, ip, school_id, etc.)
            $table->string('key', 191)->index();

            // Type of rate limit (login, scan, api, export)
            $table->string('limiter_type', 50)->index();

            // The minute window (Unix timestamp / 60)
            $table->unsignedBigInteger('minute_window')->index();

            // Number of requests in this window
            $table->unsignedInteger('hit_count')->default(1);

            // Maximum allowed (for reference/auditing)
            $table->unsignedInteger('max_allowed')->nullable();

            // Additional context
            $table->string('ip_address', 45)->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('school_id')->nullable()->index();

            // Timestamps
            $table->timestamp('first_hit_at')->useCurrent();
            $table->timestamp('last_hit_at')->useCurrent();
            $table->timestamp('expires_at')->index();

            // Composite unique index for efficient upserts
            $table->unique(['key', 'limiter_type', 'minute_window'], 'rate_limit_unique_window');

            // Index for cleanup job
            $table->index(['expires_at'], 'rate_limit_cleanup_idx');
        });

        // System health state table for tracking degraded mode
        Schema::create('system_health_state', function (Blueprint $table) {
            $table->id();

            // Component being monitored
            $table->string('component', 50)->unique(); // redis, database, queue, cache

            // Current state
            $table->enum('state', ['healthy', 'degraded', 'critical', 'unknown'])->default('unknown');

            // Last successful check
            $table->timestamp('last_healthy_at')->nullable();

            // Last failure
            $table->timestamp('last_failure_at')->nullable();

            // Consecutive failures count
            $table->unsignedInteger('consecutive_failures')->default(0);

            // Response time in milliseconds (for DB slow detection)
            $table->unsignedInteger('last_response_ms')->nullable();

            // Error message from last failure
            $table->text('last_error')->nullable();

            // Metadata
            $table->json('metadata')->nullable();

            $table->timestamps();
        });

        // Fallback events log for auditing when fallbacks are used
        Schema::create('fallback_events', function (Blueprint $table) {
            $table->id();

            // Event type
            $table->string('event_type', 100)->index(); // policy_fallback_used, rate_limit_fallback, etc.

            // Severity
            $table->enum('severity', ['info', 'warning', 'error', 'critical'])->default('warning');

            // Component that triggered fallback
            $table->string('component', 50)->index();

            // Original error that caused fallback
            $table->text('original_error')->nullable();

            // Fallback action taken
            $table->string('fallback_action', 255);

            // Context data
            $table->json('context')->nullable();

            // Related user/school
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('school_id')->nullable()->index();

            // Request info
            $table->string('ip_address', 45)->nullable();
            $table->string('request_path', 255)->nullable();

            // Timestamps
            $table->timestamp('created_at')->useCurrent()->index();

            // Index for reporting
            $table->index(['event_type', 'created_at'], 'fallback_events_reporting_idx');
            $table->index(['component', 'created_at'], 'fallback_events_component_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fallback_events');
        Schema::dropIfExists('system_health_state');
        Schema::dropIfExists('rate_limit_fallback');
    }
};
