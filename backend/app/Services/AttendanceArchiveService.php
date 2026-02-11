<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance Archive Service
 * 
 * Handles querying across main and archive tables
 * Automatically determines which tables to query based on date range
 * 
 * @version 1.0.0
 */
class AttendanceArchiveService
{
    /**
     * Get attendance records with automatic archive detection
     * 
     * @param array $filters
     * @return \Illuminate\Support\Collection
     */
    public function getAttendances(array $filters = [])
    {
        $startDate = $filters['start_date'] ?? null;
        $endDate = $filters['end_date'] ?? null;
        $schoolId = $filters['school_id'] ?? null;
        $classId = $filters['class_id'] ?? null;
        $studentId = $filters['student_id'] ?? null;
        $status = $filters['status'] ?? null;

        // Determine which tables to query
        $tables = $this->determineTables($startDate, $endDate);

        if (empty($tables)) {
            return collect([]);
        }

        // Build union query
        $queries = [];
        
        foreach ($tables as $table) {
            $query = DB::table($table)
                ->select([
                    'id',
                    'school_id',
                    'student_id',
                    'class_id',
                    'session_id',
                    'attendance_date',
                    'check_in_time',
                    'check_out_time',
                    'status',
                    'latitude',
                    'longitude',
                    'location_address',
                    'is_manual',
                    'recorded_by',
                    'notes',
                    'device_id',
                    'device_name',
                    'ip_address',
                    'created_at',
                    'updated_at',
                    DB::raw("'{$table}' as source_table")
                ]);

            // Apply filters
            if ($startDate) {
                $query->where('attendance_date', '>=', $startDate);
            }
            if ($endDate) {
                $query->where('attendance_date', '<=', $endDate);
            }
            if ($schoolId) {
                $query->where('school_id', $schoolId);
            }
            if ($classId) {
                $query->where('class_id', $classId);
            }
            if ($studentId) {
                $query->where('student_id', $studentId);
            }
            if ($status) {
                $query->where('status', $status);
            }

            $queries[] = $query;
        }

        // Union all queries
        $unionQuery = $queries[0];
        for ($i = 1; $i < count($queries); $i++) {
            $unionQuery->unionAll($queries[$i]);
        }

        return DB::query()
            ->fromSub($unionQuery, 'combined_attendances')
            ->orderBy('attendance_date', 'desc')
            ->orderBy('check_in_time', 'desc')
            ->get();
    }

    /**
     * Get attendance statistics with archive support
     * 
     * @param array $filters
     * @return array
     */
    public function getStatistics(array $filters = []): array
    {
        $startDate = $filters['start_date'] ?? null;
        $endDate = $filters['end_date'] ?? null;
        $schoolId = $filters['school_id'] ?? null;
        $classId = $filters['class_id'] ?? null;

        $tables = $this->determineTables($startDate, $endDate);

        if (empty($tables)) {
            return $this->emptyStats();
        }

        // Build union query for statistics
        $queries = [];
        
        foreach ($tables as $table) {
            $query = DB::table($table)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                    SUM(CASE WHEN status = 'sick' THEN 1 ELSE 0 END) as sick,
                    SUM(CASE WHEN status = 'permit' THEN 1 ELSE 0 END) as permit,
                    COUNT(DISTINCT student_id) as unique_students,
                    COUNT(DISTINCT attendance_date) as school_days
                ");

            if ($startDate) {
                $query->where('attendance_date', '>=', $startDate);
            }
            if ($endDate) {
                $query->where('attendance_date', '<=', $endDate);
            }
            if ($schoolId) {
                $query->where('school_id', $schoolId);
            }
            if ($classId) {
                $query->where('class_id', $classId);
            }

            $queries[] = $query;
        }

        // Union and aggregate
        $unionQuery = $queries[0];
        for ($i = 1; $i < count($queries); $i++) {
            $unionQuery->unionAll($queries[$i]);
        }

        $results = DB::query()
            ->fromSub($unionQuery, 'stats')
            ->selectRaw("
                SUM(total) as total,
                SUM(present) as present,
                SUM(late) as late,
                SUM(absent) as absent,
                SUM(sick) as sick,
                SUM(permit) as permit,
                MAX(unique_students) as unique_students,
                MAX(school_days) as school_days
            ")
            ->first();

        $total = (int) ($results->total ?? 0);
        $present = (int) ($results->present ?? 0);
        $late = (int) ($results->late ?? 0);
        $attendanceRate = $total > 0 ? round((($present + $late) / $total) * 100, 2) : 0;

        return [
            'total' => $total,
            'present' => $present,
            'late' => $late,
            'absent' => (int) ($results->absent ?? 0),
            'sick' => (int) ($results->sick ?? 0),
            'permit' => (int) ($results->permit ?? 0),
            'unique_students' => (int) ($results->unique_students ?? 0),
            'school_days' => (int) ($results->school_days ?? 0),
            'attendance_rate' => $attendanceRate,
            'queried_tables' => $tables,
        ];
    }

    /**
     * Get monthly summary with archive support
     * 
     * @param int $schoolId
     * @param int $month
     * @param int $year
     * @return array
     */
    public function getMonthlySummary(int $schoolId, int $month, int $year): array
    {
        $startDate = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $endDate = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        return $this->getStatistics([
            'school_id' => $schoolId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
    }

    /**
     * Determine which tables to query based on date range
     * 
     * @param string|null $startDate
     * @param string|null $endDate
     * @return array
     */
    protected function determineTables(?string $startDate, ?string $endDate): array
    {
        $tables = [];
        $currentYear = Carbon::now()->year;

        // If no date range specified, query main table only
        if (!$startDate && !$endDate) {
            return ['attendances'];
        }

        // Parse dates
        $start = $startDate ? Carbon::parse($startDate) : Carbon::create(2020, 1, 1);
        $end = $endDate ? Carbon::parse($endDate) : Carbon::now();

        // Get all years in range
        $years = range($start->year, $end->year);

        foreach ($years as $year) {
            if ($year === $currentYear) {
                // Current year: use main table
                $tables[] = 'attendances';
            } else {
                // Past year: check if archive table exists
                $archiveTable = "attendances_{$year}";
                if (Schema::hasTable($archiveTable)) {
                    $tables[] = $archiveTable;
                } else {
                    // Fallback to main table if archive doesn't exist
                    if (!in_array('attendances', $tables)) {
                        $tables[] = 'attendances';
                    }
                }
            }
        }

        return array_unique($tables);
    }

    /**
     * Check if archive table exists for year
     * 
     * @param int $year
     * @return bool
     */
    public function archiveExists(int $year): bool
    {
        return Schema::hasTable("attendances_{$year}");
    }

    /**
     * Get list of available archive tables
     * 
     * @return array
     */
    public function getAvailableArchives(): array
    {
        $archives = [];
        $currentYear = Carbon::now()->year;

        // Check for archive tables from 2020 to current year - 1
        for ($year = 2020; $year < $currentYear; $year++) {
            $table = "attendances_{$year}";
            if (Schema::hasTable($table)) {
                $count = DB::table($table)->count();
                $archives[] = [
                    'year' => $year,
                    'table' => $table,
                    'record_count' => $count,
                    'size' => $this->getTableSize($table),
                ];
            }
        }

        return $archives;
    }

    /**
     * Get table size (PostgreSQL)
     * 
     * @param string $table
     * @return string
     */
    protected function getTableSize(string $table): string
    {
        try {
            $result = DB::select("SELECT pg_size_pretty(pg_total_relation_size(?)) as size", [$table]);
            return $result[0]->size ?? 'Unknown';
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }

    /**
     * Empty statistics array
     * 
     * @return array
     */
    protected function emptyStats(): array
    {
        return [
            'total' => 0,
            'present' => 0,
            'late' => 0,
            'absent' => 0,
            'sick' => 0,
            'permit' => 0,
            'unique_students' => 0,
            'school_days' => 0,
            'attendance_rate' => 0,
            'queried_tables' => [],
        ];
    }
}
