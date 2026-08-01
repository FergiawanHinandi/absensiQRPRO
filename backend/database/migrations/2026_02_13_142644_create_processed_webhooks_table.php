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
        // Check if table already exists to prevent migration errors
        if (Schema::hasTable('processed_webhooks')) {
            return;
        }

        Schema::create('processed_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('order_id')->index();
            $table->string('transaction_id')->nullable()->index();
            $table->string('status')->default('processing')->index(); // processing, success, failed
            $table->string('transaction_status')->nullable();
            $table->string('payment_type')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('gross_amount', 15, 2)->nullable();
            $table->json('payload')->nullable();
            $table->string('signature_hash')->nullable();
            $table->text('processing_notes')->nullable();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamps();

            // Unique constraint to prevent duplicate processing
            $table->unique('order_id', 'unique_order_id');
            
            // Composite index for common queries
            $table->index(['order_id', 'status'], 'idx_order_status');
            $table->index(['transaction_id', 'status'], 'idx_transaction_status');
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
