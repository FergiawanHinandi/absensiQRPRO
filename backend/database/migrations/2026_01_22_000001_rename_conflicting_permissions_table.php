<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if 'permissions' table exists and has 'student_id' column (identifying it as the student permission table)
        if (Schema::hasTable('permissions') && Schema::hasColumn('permissions', 'student_id')) {
            Schema::rename('permissions', 'student_permissions');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('student_permissions')) {
            Schema::rename('student_permissions', 'permissions');
        }
    }
};
