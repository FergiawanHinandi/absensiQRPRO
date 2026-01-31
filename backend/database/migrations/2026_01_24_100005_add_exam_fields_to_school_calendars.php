<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('school_calendars')) {
            Schema::table('school_calendars', function (Blueprint $table) {
                $table->time('exam_start_time')->nullable()->after('type');
                $table->time('exam_end_time')->nullable()->after('exam_start_time');
                $table->string('exam_code', 50)->nullable()->after('exam_end_time');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('school_calendars')) {
            Schema::table('school_calendars', function (Blueprint $table) {
                $table->dropColumn(['exam_start_time', 'exam_end_time', 'exam_code']);
            });
        }
    }
};
