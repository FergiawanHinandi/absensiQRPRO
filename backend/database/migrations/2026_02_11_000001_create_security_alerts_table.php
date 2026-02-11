<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merged security_alerts migration
 *
 * Combines columns from:
 * - 2026_01_29_103100 (original: resolution tracking, details json)
 * - 2026_01_30_000002 (fix: school_id FK, ip_address)
 *
 * Both archived to _archived/ directory.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('security_alerts')) {
            return;
        }

        Schema::create('security_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->cascadeOnDelete();
            $table->string('type')->default('login')->comment('qr_replay_spike, login_attack, cross_school_access');
            $table->string('severity')->default('medium')->comment('low, medium, high, critical');
            $table->text('description')->nullable();
            $table->json('details')->nullable()->comment('Count, source IPs, affected users');
            $table->ipAddress('ip_address')->nullable();
            $table->timestamp('detected_at')->useCurrent();
            $table->boolean('is_resolved')->default(false);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['school_id', 'type', 'created_at']);
            $table->index(['severity', 'is_resolved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_alerts');
    }
};
