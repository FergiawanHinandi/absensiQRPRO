<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates refresh_tokens table for secure token rotation.
     * Refresh tokens are stored hashed for security.
     */
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            
            // User relationship
            $table->foreignId('user_id')
                ->constrained()
                ->onDelete('cascade');
            
            // Hashed token (using SHA-256)
            $table->string('token_hash', 64)->unique();
            
            // Associated Sanctum access token (for revocation chain)
            $table->foreignId('personal_access_token_id')
                ->nullable()
                ->constrained('personal_access_tokens')
                ->onDelete('cascade');
            
            // Device binding metadata
            $table->string('device_fingerprint', 64)->nullable();
            $table->string('device_name', 255)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('platform', 50)->nullable(); // web, android, ios
            
            // IP binding (for admin accounts)
            $table->string('initial_ip', 45)->nullable();
            $table->string('initial_country', 2)->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->string('last_country', 2)->nullable();
            
            // Token lifecycle
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 100)->nullable();
            
            // Rotation tracking
            $table->unsignedBigInteger('previous_token_id')->nullable();
            $table->unsignedInteger('rotation_count')->default(0);
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['user_id', 'revoked_at']);
            $table->index(['expires_at', 'revoked_at']);
            $table->index('device_fingerprint');
        });

        // Add expires_at to personal_access_tokens if not exists
        if (!Schema::hasColumn('personal_access_tokens', 'expires_at')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->timestamp('expires_at')->nullable()->after('last_used_at');
            });
        }

        // Add device/IP binding columns to personal_access_tokens
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('device_fingerprint', 64)->nullable()->after('expires_at');
            $table->string('initial_ip', 45)->nullable()->after('device_fingerprint');
            $table->string('initial_country', 2)->nullable()->after('initial_ip');
            $table->string('platform', 50)->nullable()->after('initial_country');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn([
                'device_fingerprint',
                'initial_ip',
                'initial_country',
                'platform',
            ]);
        });
    }
};
