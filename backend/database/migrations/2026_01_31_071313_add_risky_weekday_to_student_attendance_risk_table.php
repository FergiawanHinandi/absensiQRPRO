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
        Schema::table('student_attendance_risk', function (Blueprint $table) {
            $table->string('risky_weekday')->nullable()->after('risk_level'); // e.g., 'Monday'
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_attendance_risk', function (Blueprint $table) {
            $table->dropColumn('risky_weekday');
        });
    }
};
