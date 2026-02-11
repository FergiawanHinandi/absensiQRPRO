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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            
            // Subscription details
            $table->enum('plan_type', ['free', 'basic', 'premium', 'enterprise'])->default('free');
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            
            // Limits
            $table->integer('max_students')->default(50); // Free tier limit
            $table->integer('max_teachers')->default(5);
            
            // Features (JSON)
            $table->json('features')->nullable(); // e.g., ["reports", "analytics", "api_access"]
            
            // Cancellation
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['school_id', 'is_active', 'expires_at'], 'idx_subscription_active');
            $table->index('expires_at', 'idx_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
