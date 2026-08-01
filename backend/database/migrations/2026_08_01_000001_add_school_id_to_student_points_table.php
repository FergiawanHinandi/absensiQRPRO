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
        Schema::table('student_points', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->after('student_id')->constrained('schools')->onDelete('cascade');
            $table->index('school_id', 'idx_student_points_school');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_points', function (Blueprint $table) {
            $table->dropIndex('idx_student_points_school');
            $table->dropForeign(['school_id']);
            $table->dropColumn('school_id');
        });
    }
};
