<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('processed_webhooks')) {
            return;
        }

        Schema::create('processed_webhooks', function (Blueprint $table) {
            $table->id();
            // Critical: Unique Constraint for Idempotency
            $table->string('order_id')->unique();
            $table->string('transaction_id')->unique();
            $table->string('status')->default('processing'); // processing, success, failed
            $table->string('transaction_status')->nullable();
            $table->string('payment_type')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('gross_amount', 15, 2)->nullable();
            $table->json('payload')->nullable();
            $table->string('signature_hash')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            
            // Index for faster lookups
            $table->index('processed_at');
            $table->index('status');
            $table->index(['order_id', 'status']);
            $table->index(['transaction_id', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('processed_webhooks');
    }
};
