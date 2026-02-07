<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Cek duplikasi scan (dipakai setiap scan)
        // Index untuk mencegah duplikasi attendance pada schedule yang sama di tanggal yang sama
        if (!$this->indexExists('attendances', 'idx_attendance_unique_check')) {
            DB::statement('CREATE INDEX idx_attendance_unique_check ON attendances (student_id, schedule_id, attendance_date)');
        }

        // Riwayat siswa
        // Index untuk query riwayat attendance siswa dengan sorting tanggal descending
        if (!$this->indexExists('attendances', 'idx_attendance_student_date')) {
            DB::statement('CREATE INDEX idx_attendance_student_date ON attendances (student_id, attendance_date DESC)');
        }

        // Laporan kelas harian
        // Index untuk laporan attendance per schedule/kelas pada tanggal tertentu
        if (!$this->indexExists('attendances', 'idx_attendance_schedule_date')) {
            DB::statement('CREATE INDEX idx_attendance_schedule_date ON attendances (schedule_id, attendance_date)');
        }

        // Rekap sekolah bulanan
        // Index untuk laporan rekap attendance per sekolah dalam rentang tanggal
        if (!$this->indexExists('attendances', 'idx_attendance_school_date')) {
            DB::statement('CREATE INDEX idx_attendance_school_date ON attendances (school_id, attendance_date)');
        }

        // Filter status (late/absent monitoring)
        // Index untuk monitoring siswa terlambat atau tidak hadir
        if (!$this->indexExists('attendances', 'idx_attendance_status')) {
            DB::statement('CREATE INDEX idx_attendance_status ON attendances (status)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop indexes in reverse order
        if ($this->indexExists('attendances', 'idx_attendance_status')) {
            DB::statement('DROP INDEX idx_attendance_status');
        }

        if ($this->indexExists('attendances', 'idx_attendance_school_date')) {
            DB::statement('DROP INDEX idx_attendance_school_date');
        }

        if ($this->indexExists('attendances', 'idx_attendance_schedule_date')) {
            DB::statement('DROP INDEX idx_attendance_schedule_date');
        }

        if ($this->indexExists('attendances', 'idx_attendance_student_date')) {
            DB::statement('DROP INDEX idx_attendance_student_date');
        }

        if ($this->indexExists('attendances', 'idx_attendance_unique_check')) {
            DB::statement('DROP INDEX idx_attendance_unique_check');
        }
    }

    /**
     * Check if an index exists on a table
     */
    private function indexExists(string $table, string $index): bool
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        switch ($driver) {
            case 'sqlite':
                $result = DB::select("SELECT name FROM sqlite_master WHERE type='index' AND name=?", [$index]);
                return !empty($result);
            
            case 'mysql':
                $result = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$index]);
                return !empty($result);
            
            case 'pgsql':
                $result = DB::select("SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?", [$table, $index]);
                return !empty($result);
            
            default:
                return false;
        }
    }
};