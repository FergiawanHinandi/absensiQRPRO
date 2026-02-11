<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * IDEMPOTENCY KEY STORAGE
     * ========================
     * Stores idempotency keys to prevent replay attacks and duplicate submissions.
     *
     * KEY FEATURES:
     * - TTL-based expiration (auto-cleanup via scheduled job)
     * - Composite unique constraint on (key + user_id)
     * - Stores response payload for consistent retry responses
     * - Indexed for fast lookup
     *
     * SECURITY BENEFITS:
     * - Prevents replay attacks (same key cannot be reused)
     * - Prevents double submissions (network retry safety)
     * - Enables offline sync with pre-generated keys
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            
            // Idempotency key (UUID from client)
            $table->string('key', 64)->index();
            
            // User who made the request
            $table->unsignedBigInteger('user_id')->index();
            
            // Request fingerprint (endpoint + method)
            $table->string('endpoint', 255);
            $table->string('http_method', 10)->default('POST');
            
            // Request metadata
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('device_id', 255)->nullable();
            
            // Response data (for consistent retry responses)
            $table->text('response_payload')->nullable();
            $table->unsignedSmallInteger('response_status')->default(200);
            
            // TTL tracking
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at');
            
            // Composite unique constraint: same key cannot be reused by same user
            $table->unique(['key', 'user_id', 'endpoint'], 'idempotency_unique');
            
            // Foreign key
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });

        // Index for cleanup job (find expired keys)
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->index(['expires_at', 'created_at'], 'cleanup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
