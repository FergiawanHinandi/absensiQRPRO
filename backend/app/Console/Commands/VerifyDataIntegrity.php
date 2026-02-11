<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\User;
use App\ReadModels\AttendanceDailySummary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VerifyDataIntegrity extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'integrity:verify {--school= : Optional school ID to limit check} {--fix : Attempt to fix simple inconsistencies}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify data integrity between Write Model, Read Model, and Tenant Isolation';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Data Integrity Verification...');
        $schoolId = $this->option('school');
        $fix = $this->option('fix');

        $this->checkCrossTenantLeaks();
        $this->checkOrphanedRecords();
        $this->checkSmartReadModelConsistency($schoolId, $fix);
        
        $this->info('Integrity check complete.');
    }

    /**
     * Check 1: Cross-Tenant Data Leaks
     * Ensure attendance records belong to the same school as the student.
     */
    private function checkCrossTenantLeaks()
    {
        $this->section('Checking Cross-Tenant Leaks');

        $leaks = DB::table('attendances as a')
            ->join('users as s', 'a.student_id', '=', 's.id')
            ->whereColumn('a.school_id', '!=', 's.school_id')
            ->select('a.id', 'a.student_id', 'a.school_id as att_school', 's.school_id as stu_school')
            ->limit(50)
            ->get();

        if ($leaks->isEmpty()) {
            $this->info('✅ No cross-tenant leaks found.');
        } else {
            $this->error("❌ Found {$leaks->count()} cross-tenant leaks!");
            foreach ($leaks as $leak) {
                $this->line("   - Attendance #{$leak->id}: Student {$leak->student_id} (School {$leak->stu_school}) has attendance in School {$leak->att_school}");
            }
        }
    }

    /**
     * Check 2: Orphaned Records
     * Attendance records pointing to non-existent students.
     */
    private function checkOrphanedRecords()
    {
        $this->section('Checking Orphaned Records');

        $orphans = DB::table('attendances as a')
            ->leftJoin('users as s', 'a.student_id', '=', 's.id')
            ->whereNull('s.id')
            ->select('a.id', 'a.student_id')
            ->limit(50)
            ->get();

        if ($orphans->isEmpty()) {
            $this->info('✅ No orphaned attendance records found.');
        } else {
            $this->error("❌ Found {$orphans->count()} orphaned records!");
            // Implementation note: You might want to delete these if --fix is passed
        }
    }

    /**
     * Check 3: Read Model Consistency (CQRS)
     * Compare raw aggregation of 'attendances' table vs 'attendance_daily_summaries' table.
     */
    private function checkSmartReadModelConsistency($schoolId = null, $fix = false)
    {
        $this->section('Checking Read Model Consistency (CQRS)');

        $query = Attendance::query();
        if ($schoolId) {
            $query->where('school_id', $schoolId);
        }
        
        // Check last 7 days only for performance, unless specified otherwise
        $datesToCheck = $query->distinct()
            ->where('attendance_date', '>=', now()->subDays(7)->toDateString())
            ->pluck('attendance_date');

        $inconsistencies = 0;

        foreach ($datesToCheck as $date) {
            // 1. Calculate Truth (Write Model)
            $truthQuery = Attendance::where('attendance_date', $date);
            if ($schoolId) $truthQuery->where('school_id', $schoolId);
            
            // Group by school to compare
            $truthStats = $truthQuery->select(
                'school_id',
                DB::raw('count(*) as total'),
                DB::raw("sum(case when status in ('present', 'hadir') then 1 else 0 end) as present"),
                DB::raw("sum(case when status in ('late', 'terlambat') then 1 else 0 end) as late"),
                DB::raw("sum(case when status in ('absent', 'alpha') then 1 else 0 end) as absent"),
                DB::raw("sum(case when status in ('excused', 'izin', 'sakit') then 1 else 0 end) as excused")
            )->groupBy('school_id')->get()->keyBy('school_id');

            // 2. Fetch Read Model
            $readQuery = AttendanceDailySummary::where('attendance_date', $date)->schoolWide();
            if ($schoolId) $readQuery->where('school_id', $schoolId);
            $readStats = $readQuery->get()->keyBy('school_id');

            // 3. Compare
            foreach ($truthStats as $sId => $truth) {
                $read = $readStats->get($sId);
                
                if (!$read) {
                    $this->warn("⚠️  Missing Read Model for School {$sId} on {$date}");
                    if ($fix) $this->fixSummary($sId, $date);
                    $inconsistencies++;
                    continue;
                }

                $isConsistent = 
                    $read->total_students == $truth->total &&
                    $read->total_present == $truth->present &&
                    $read->total_late == $truth->late &&
                    $read->total_absent == $truth->absent &&
                    $read->total_excused == $truth->excused;

                if (!$isConsistent) {
                    $this->error("❌ Inconsistency School {$sId} on {$date}:");
                    $this->line("   Truth: Total={$truth->total}, P={$truth->present}, L={$truth->late}, A={$truth->absent}, E={$truth->excused}");
                    $this->line("   Read : Total={$read->total_students}, P={$read->total_present}, L={$read->total_late}, A={$read->total_absent}, E={$read->total_excused}");
                    
                    if ($fix) {
                        $this->fixSummary($sId, $date);
                        $this->info("   ✅ Fixed.");
                    }
                    $inconsistencies++;
                }
            }
        }

        if ($inconsistencies === 0) {
            $this->info('✅ Read Models are consistent with Write Models (Last 7 days).');
        }
    }

    private function fixSummary($schoolId, $date)
    {
        $projector = new \App\ReadModels\Projectors\AttendanceSummaryProjector();
        $projector->projectAllClassesForDate($schoolId, $date);
    }

    private function section($title)
    {
        $this->line('');
        $this->info("=== $title ===");
        $this->line('');
    }
}
