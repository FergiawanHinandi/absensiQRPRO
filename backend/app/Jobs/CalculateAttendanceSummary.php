<?php

namespace App\Jobs;

use App\Models\Attendance;
use App\Models\AttendanceSummary;
use App\Models\School;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CalculateAttendanceSummary implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $targetDate;

    /**
     * Create a new job instance.
     *
     * @param  Carbon|null  $date  Date to calculate summary for (defaults to today)
     */
    public function __construct($date = null)
    {
        $this->targetDate = $date ? Carbon::parse($date) : now();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $year = $this->targetDate->year;
        $month = $this->targetDate->month;

        Log::info("Starting Attendance Summary Calculation for {$year}-{$month}");

        // Process by School to handle timezones correctly in future if needed
        // For now, we process all active schools
        School::where('is_active', true)->chunk(10, function ($schools) use ($year, $month) {
            foreach ($schools as $school) {
                $this->processSchool($school, $year, $month);
            }
        });

        Log::info("Completed Attendance Summary Calculation for {$year}-{$month}");
    }

    protected function processSchool(School $school, int $year, int $month)
    {
        // Get all students in this school
        // Use chunking to avoid memory issues
        User::where('school_id', $school->id)
            ->where('role_type', 'student') // Assuming 'student' role identifier
            ->chunk(100, function ($students) use ($year, $month) {
                foreach ($students as $student) {
                    $this->calculateStudentSummary($student, $year, $month);
                }
            });
    }

    protected function calculateStudentSummary(User $student, int $year, int $month)
    {
        // Aggregate attendance counts for this student in the given month
        $stats = Attendance::where('student_id', $student->id)
            ->whereYear('attendance_date', $year)
            ->whereMonth('attendance_date', $month)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Get current active class for snapshot
        $classId = $student->classStudent->class_id ?? null;

        // Map status to summary columns
        $data = [
            'present' => ($stats['present'] ?? 0),
            'late' => ($stats['late'] ?? 0),
            'sick' => ($stats['sick'] ?? 0),
            'absent' => ($stats['absent'] ?? 0) + ($stats['alpha'] ?? 0), // Merge alpha into absent if needed
            'permit' => ($stats['permit'] ?? 0) + ($stats['excused'] ?? 0),
        ];

        // Update or Create Summary
        AttendanceSummary::updateOrCreate(
            [
                'school_id' => $student->school_id,
                'student_id' => $student->id,
                'year' => $year,
                'month' => $month,
            ],
            array_merge($data, ['class_id' => $classId])
        );
    }
}
