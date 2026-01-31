<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->integer('day_of_week_tmp')->nullable()->after('teacher_id');
        });

        $map = [
            'sunday' => 0,
            'monday' => 1,
            'tuesday' => 2,
            'wednesday' => 3,
            'thursday' => 4,
            'friday' => 5,
            'saturday' => 6,
        ];

        foreach ($map as $name => $int) {
            DB::table('schedules')
                ->where('day_of_week', $name)
                ->update(['day_of_week_tmp' => $int]);
        }

        // Drop indexes that reference day_of_week string
        try {
            Schema::table('schedules', function (Blueprint $table) {
                $table->dropIndex('idx_schedules_class');
                $table->dropIndex('idx_schedules_teacher');
            });
        } catch (\Throwable $e) {
            // ignore if not exists
        }

        try {
            DB::statement('DROP INDEX IF EXISTS idx_schedule_day');
            DB::statement('DROP INDEX IF EXISTS idx_schedule_school_day');
        } catch (\Throwable $e) {
            // ignore
        }

        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn('day_of_week');
        });

        Schema::table('schedules', function (Blueprint $table) {
            $table->integer('day_of_week')->after('teacher_id');
        });

        DB::table('schedules')->update([
            'day_of_week' => DB::raw('day_of_week_tmp'),
        ]);

        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn('day_of_week_tmp');
        });

        // Recreate indexes for integer column
        Schema::table('schedules', function (Blueprint $table) {
            $table->index(['class_id', 'day_of_week'], 'idx_schedules_class');
            $table->index(['teacher_id', 'day_of_week'], 'idx_schedules_teacher');
        });

        try {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_schedule_day ON schedules (day_of_week)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_schedule_school_day ON schedules (school_id, day_of_week)');
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->enum('day_of_week_tmp', ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])->nullable()->after('teacher_id');
        });

        $reverse = [
            0 => 'sunday',
            1 => 'monday',
            2 => 'tuesday',
            3 => 'wednesday',
            4 => 'thursday',
            5 => 'friday',
            6 => 'saturday',
        ];

        foreach ($reverse as $int => $name) {
            DB::table('schedules')
                ->where('day_of_week', $int)
                ->update(['day_of_week_tmp' => $name]);
        }

        // Drop indexes for integer column
        try {
            Schema::table('schedules', function (Blueprint $table) {
                $table->dropIndex('idx_schedules_class');
                $table->dropIndex('idx_schedules_teacher');
            });
        } catch (\Throwable $e) {
            // ignore
        }

        try {
            DB::statement('DROP INDEX IF EXISTS idx_schedule_day');
            DB::statement('DROP INDEX IF EXISTS idx_schedule_school_day');
        } catch (\Throwable $e) {
            // ignore
        }

        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn('day_of_week');
        });

        Schema::table('schedules', function (Blueprint $table) {
            $table->enum('day_of_week', ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])->after('teacher_id');
        });

        DB::table('schedules')->update([
            'day_of_week' => DB::raw('day_of_week_tmp'),
        ]);

        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn('day_of_week_tmp');
        });

        // Recreate original indexes
        Schema::table('schedules', function (Blueprint $table) {
            $table->index(['class_id', 'day_of_week'], 'idx_schedules_class');
            $table->index(['teacher_id', 'day_of_week'], 'idx_schedules_teacher');
        });
    }
};
