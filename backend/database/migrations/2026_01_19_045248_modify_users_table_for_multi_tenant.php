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
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('school_id')->after('id')->nullable()->constrained('schools')->onDelete('cascade');
            $table->string('username', 100)->after('school_id')->unique();
            $table->enum('role_type', ['super_admin', 'admin', 'school_admin', 'principal', 'vice_principal', 'teacher', 'homeroom_teacher', 'staff', 'student', 'parent'])
                ->after('password');
            $table->boolean('is_active')->default(true)->after('role_type');
            $table->string('device_token', 255)->nullable()->after('remember_token')->comment('FCM token');
            $table->timestamp('last_login_at')->nullable()->after('device_token');

            // Indexes
            $table->index('school_id', 'idx_users_school');
            $table->index('username', 'idx_users_username');
            $table->index('role_type', 'idx_users_role');
            $table->index(['is_active', 'school_id'], 'idx_users_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_school');
            $table->dropIndex('idx_users_username');
            $table->dropIndex('idx_users_role');
            $table->dropIndex('idx_users_active');

            $table->dropForeign(['school_id']);
            $table->dropColumn([
                'school_id',
                'username',
                'role_type',
                'is_active',
                'device_token',
                'last_login_at',
            ]);
        });
    }
};
