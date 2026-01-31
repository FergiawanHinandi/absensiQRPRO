<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enhance security_alerts table with user/school tracking
 * and notification status
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_alerts', function (Blueprint $table) {
            // Add user and school tracking
            if (!Schema::hasColumn('security_alerts', 'related_user_id')) {
                $table->foreignId('related_user_id')->nullable()->after('details')
                    ->constrained('users')->nullOnDelete();
            }
            
            if (!Schema::hasColumn('security_alerts', 'school_id')) {
                $table->foreignId('school_id')->nullable()->after('related_user_id')
                    ->constrained('schools')->nullOnDelete();
            }
            
            // Add notification tracking
            if (!Schema::hasColumn('security_alerts', 'notification_sent')) {
                $table->boolean('notification_sent')->default(false)->after('is_resolved');
            }
            
            if (!Schema::hasColumn('security_alerts', 'notification_sent_at')) {
                $table->timestamp('notification_sent_at')->nullable()->after('notification_sent');
            }
            
            // Add IP and device info
            if (!Schema::hasColumn('security_alerts', 'ip_address')) {
                $table->string('ip_address', 45)->nullable()->after('school_id');
            }
            
            if (!Schema::hasColumn('security_alerts', 'device_id')) {
                $table->string('device_id')->nullable()->after('ip_address');
            }
            
            // Add event_type alias for consistency
            // Rename 'type' to 'event_type' for clarity
        });
        
        // Add indexes for better querying
        Schema::table('security_alerts', function (Blueprint $table) {
            $table->index(['school_id', 'severity'], 'idx_alert_school_severity');
            $table->index(['related_user_id', 'created_at'], 'idx_alert_user_time');
            $table->index(['type', 'created_at'], 'idx_alert_type_time');
            $table->index(['notification_sent', 'severity'], 'idx_alert_notify_severity');
        });
    }

    public function down(): void
    {
        Schema::table('security_alerts', function (Blueprint $table) {
            $table->dropIndex('idx_alert_school_severity');
            $table->dropIndex('idx_alert_user_time');
            $table->dropIndex('idx_alert_type_time');
            $table->dropIndex('idx_alert_notify_severity');
            
            $table->dropColumn([
                'related_user_id',
                'school_id',
                'notification_sent',
                'notification_sent_at',
                'ip_address',
                'device_id',
            ]);
        });
    }
};
