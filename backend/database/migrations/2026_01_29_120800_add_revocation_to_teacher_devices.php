<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add revocation columns to teacher_devices table
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_devices', function (Blueprint $table) {
            // Add revocation columns if they don't exist
            if (!Schema::hasColumn('teacher_devices', 'revoked_at')) {
                $table->timestamp('revoked_at')->nullable()->after('last_used_at');
            }
            if (!Schema::hasColumn('teacher_devices', 'revoked_by')) {
                $table->foreignId('revoked_by')->nullable()->after('revoked_at')
                    ->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('teacher_devices', 'revoke_reason')) {
                $table->string('revoke_reason', 255)->nullable()->after('revoked_by');
            }
            if (!Schema::hasColumn('teacher_devices', 'last_used_ip')) {
                $table->string('last_used_ip', 45)->nullable()->after('last_used_at');
            }
            if (!Schema::hasColumn('teacher_devices', 'os_version')) {
                $table->string('os_version', 100)->nullable()->after('device_model');
            }
            if (!Schema::hasColumn('teacher_devices', 'app_version')) {
                $table->string('app_version', 50)->nullable()->after('os_version');
            }
        });
    }

    public function down(): void
    {
        Schema::table('teacher_devices', function (Blueprint $table) {
            $table->dropColumn([
                'revoked_at',
                'revoked_by', 
                'revoke_reason',
                'last_used_ip',
                'os_version',
                'app_version',
            ]);
        });
    }
};
