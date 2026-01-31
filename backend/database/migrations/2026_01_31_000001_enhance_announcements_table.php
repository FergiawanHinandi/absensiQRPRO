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
        Schema::table('announcements', function (Blueprint $table) {
            // Add support for targeting specific schools
            if (!Schema::hasColumn('announcements', 'target_type')) {
                $table->string('target_type', 50)->default('global'); // global, school, user
            }
            if (!Schema::hasColumn('announcements', 'target_ids')) {
                $table->jsonb('target_ids')->nullable(); // List of school IDs
            }
            if (!Schema::hasColumn('announcements', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn(['target_type', 'target_ids', 'created_by']);
        });
    }
};
