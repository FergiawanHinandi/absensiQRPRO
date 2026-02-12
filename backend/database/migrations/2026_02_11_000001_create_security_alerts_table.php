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
            $table->foreignId('related_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type')->default('login')->comment('qr_replay_spike, login_attack, cross_school_access');
            $table->string('severity')->default('medium')->comment('low, medium, high, critical');
            $table->text('description')->nullable();
            $table->json('details')->nullable()->comment('Count, source IPs, affected users');
            $table->ipAddress('ip_address')->nullable();
            $table->string('device_id')->nullable();
            $table->timestamp('detected_at')->useCurrent();
            $table->boolean('is_resolved')->default(false);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->boolean('notification_sent')->default(false);
            $table->timestamp('notification_sent_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['school_id', 'type', 'created_at']);
            $table->index(['severity', 'is_resolved']);
            $table->index(['school_id', 'severity'], 'idx_alert_school_severity');
            $table->index(['related_user_id', 'created_at'], 'idx_alert_user_time');
            $table->index(['type', 'created_at'], 'idx_alert_type_time');
            $table->index(['notification_sent', 'severity'], 'idx_alert_notify_severity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_alerts');
    }
};
