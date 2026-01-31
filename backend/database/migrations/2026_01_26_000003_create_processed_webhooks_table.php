<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CRITICAL: Create processed_webhooks table for idempotency
     *
     * FIXES:
     * - Prevent duplicate webhook processing
     * - Anti-replay attack protection
     * - Audit trail for webhook processing
     */
    public function up(): void
    {
        Schema::create('processed_webhooks', function (Blueprint $table) {
            $table->id();

            // CRITICAL: Unique identifiers to prevent duplicates
            $table->string('order_id')->unique()->index();
            $table->string('transaction_id')->unique()->index();

            // Webhook metadata
            $table->string('webhook_type')->default('payment'); // payment, subscription, etc
            $table->string('payment_method')->nullable(); // midtrans, manual, etc
            $table->string('status'); // success, failed, pending

            // Request tracking
            $table->json('webhook_payload'); // Store original payload for audit
            $table->string('signature_hash')->nullable(); // Store signature for verification
            $table->ipAddress('source_ip')->nullable();
            $table->string('user_agent')->nullable();

            // Processing metadata
            $table->timestamp('processed_at');
            $table->string('processed_by')->nullable(); // System or user ID
            $table->text('processing_notes')->nullable(); // Any notes or errors

            // Audit fields
            $table->timestamps();

            // CRITICAL: Indexes for performance
            $table->index(['webhook_type', 'status']);
            $table->index(['processed_at']);
            $table->index(['order_id', 'transaction_id']); // Composite index
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('processed_webhooks');
    }
};
