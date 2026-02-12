<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AdminDashboardService
{
    /**
     * Cache lock service
     */
    protected CacheLockService $cacheLock;

    /**
     * Constructor
     */
    public function __construct(CacheLockService $cacheLock)
    {
        $this->cacheLock = $cacheLock;
    }

    public function getClassAttendanceSummary(User $user, ?string $date = null): array
    {
        $schoolId = $user->school_id;
        $targetDate = $date ? Carbon::parse($date)->toDateString() : Carbon::today()->toDateString();
        $cacheKey = "dashboard_stats_class_{$schoolId}_{$targetDate}";

        return $this->cacheLock->remember($cacheKey, 300, function () use ($schoolId, $targetDate) {
            $classes = DB::table('classes')
                ->where('classes.school_id', $schoolId)
                ->where('classes.is_active', true)
                ->select('classes.id', 'classes.name', 'classes.grade_level')
                ->orderBy('classes.grade_level')
                ->orderBy('classes.name')
                ->get();

            $totalStudentsByClass = DB::table('class_students')
                ->join('classes', 'class_students.class_id', '=', 'classes.id')
                ->where('classes.school_id', $schoolId)
                ->where('class_students.status', 'active')
                ->groupBy('class_students.class_id')
                ->select('class_students.class_id', DB::raw('count(*) as total_students'))
                ->get()
                ->keyBy('class_id');

            $attendanceByStatus = DB::table('attendances')
                ->join('schedules', 'attendances.schedule_id', '=', 'schedules.id')
                ->where('attendances.school_id', $schoolId)
                ->whereDate('attendances.attendance_date', $targetDate)
                ->groupBy('schedules.class_id', 'attendances.status')
                ->select(
                    'schedules.class_id',
                    'attendances.status',
                    DB::raw('count(distinct attendances.student_id) as total')
                )
                ->get();

            $attendedStudentsByClass = DB::table('attendances')
                ->join('schedules', 'attendances.schedule_id', '=', 'schedules.id')
                ->where('attendances.school_id', $schoolId)
                ->whereDate('attendances.attendance_date', $targetDate)
                ->groupBy('schedules.class_id')
                ->select('schedules.class_id', DB::raw('count(distinct attendances.student_id) as attended_students'))
                ->get()
                ->keyBy('class_id');

            $statusMap = [];
            foreach ($attendanceByStatus as $row) {
                $statusMap[$row->class_id][$row->status] = (int) $row->total;
            }

            $classSummaries = $classes->map(function ($class) use ($totalStudentsByClass, $attendedStudentsByClass, $statusMap) {
                $totalStudents = (int) ($totalStudentsByClass[$class->id]->total_students ?? 0);
                $attendedStudents = (int) ($attendedStudentsByClass[$class->id]->attended_students ?? 0);
                $statuses = $statusMap[$class->id] ?? [];

                $present = (int) ($statuses['present'] ?? 0);
                $late = (int) ($statuses['late'] ?? 0);
                $sick = (int) ($statuses['sick'] ?? 0);
                $permit = (int) ($statuses['permit'] ?? 0);
                $excused = (int) ($statuses['excused'] ?? 0);
                $absent = (int) ($statuses['absent'] ?? 0);
                $alpha = max(0, $totalStudents - $attendedStudents);

                return [
                    'class_id' => $class->id,
                    'class_name' => $class->name,
                    'grade_level' => $class->grade_level,
                    'total_students' => $totalStudents,
                    'present' => $present,
                    'late' => $late,
                    'sick' => $sick,
                    'permit' => $permit,
                    'excused' => $excused,
                    'absent' => $absent,
                    'alpha' => $alpha,
                ];
            })->values();

            return [
                'date' => $targetDate,
                'classes' => $classSummaries,
            ];
        });
    }

    public function getTeacherAbsence(User $user, ?string $date = null): array
    {
        $schoolId = $user->school_id;
        $targetDate = $date ? Carbon::parse($date) : Carbon::today();
        $dateString = $targetDate->toDateString();
        $dayInt = $targetDate->dayOfWeek;
        $cacheKey = "dashboard_stats_teacher_{$schoolId}_{$dateString}";

        return $this->cacheLock->remember($cacheKey, 300, function () use ($schoolId, $dateString, $dayInt) {
            $teacherSchedules = DB::table('schedules')
                ->join('users', 'schedules.teacher_id', '=', 'users.id')
                ->where('schedules.school_id', $schoolId)
                ->where('schedules.is_active', true)
                ->where('schedules.day_of_week', $dayInt)
                ->whereIn('users.role_type', ['teacher', 'homeroom_teacher'])
                ->groupBy('schedules.teacher_id', 'users.name')
                ->select(
                    'schedules.teacher_id',
                    'users.name as teacher_name',
                    DB::raw('count(*) as total_schedules')
                )
                ->get()
                ->keyBy('teacher_id');

            // Check actual attendance records (implies teacher was present/processed it)
            $attendanceRecorded = DB::table('attendances')
                ->join('schedules', 'attendances.schedule_id', '=', 'schedules.id')
                ->where('attendances.school_id', $schoolId)
                ->whereDate('attendances.attendance_date', $dateString)
                ->groupBy('schedules.teacher_id')
                ->select(
                    'schedules.teacher_id',
                    DB::raw('count(distinct attendances.schedule_id) as attended_schedules')
                )
                ->get()
                ->keyBy('teacher_id');

            $teachers = $teacherSchedules->map(function ($row) use ($attendanceRecorded) {
                $attendedCount = (int) ($attendanceRecorded[$row->teacher_id]->attended_schedules ?? 0);
                $missing = max(0, (int) $row->total_schedules - $attendedCount);

                return [
                    'teacher_id' => $row->teacher_id,
                    'teacher_name' => $row->teacher_name,
                    'total_schedules' => (int) $row->total_schedules,
                    'qr_generated' => $attendedCount, // Reuse key for compatibility, means "processed sessions"
                    'missing_qr' => $missing,
                ];
            })->values();

            $absentTeachers = $teachers->filter(fn ($item) => $item['missing_qr'] > 0)->values();

            return [
                'date' => $dateString,
                'total_teachers_scheduled' => $teachers->count(),
                'total_teachers_absent' => $absentTeachers->count(),
                'teachers' => $absentTeachers,
            ];
        });
    }

    public function getLateAlpha(User $user, ?string $date = null): array
    {
        $schoolId = $user->school_id;
        $targetDate = $date ? Carbon::parse($date)->toDateString() : Carbon::today()->toDateString();
        $cacheKey = "dashboard_stats_late_{$schoolId}_{$targetDate}";

        return $this->cacheLock->remember($cacheKey, 300, function () use ($schoolId, $targetDate) {
            $lateStudents = DB::table('attendances')
                ->join('users', 'attendances.student_id', '=', 'users.id')
                ->join('schedules', 'attendances.schedule_id', '=', 'schedules.id')
                ->join('classes', 'schedules.class_id', '=', 'classes.id')
                ->where('attendances.school_id', $schoolId)
                ->whereDate('attendances.attendance_date', $targetDate)
                ->where('attendances.status', 'late')
                ->select(
                    'users.id as student_id',
                    'users.name as student_name',
                    'users.username',
                    'classes.name as class_name',
                    'classes.grade_level',
                    'attendances.check_in_time'
                )
                ->orderBy('attendances.check_in_time')
                ->limit(10)
                ->get();

            $lateCount = DB::table('attendances')
                ->where('school_id', $schoolId)
                ->whereDate('attendance_date', $targetDate)
                ->where('status', 'late')
                ->distinct('student_id')
                ->count('student_id');

            $attendedStudents = DB::table('attendances')
                ->where('school_id', $schoolId)
                ->whereDate('attendance_date', $targetDate)
                ->select('student_id');

            $alphaStudents = DB::table('class_students')
                ->join('users', 'class_students.student_id', '=', 'users.id')
                ->join('classes', 'class_students.class_id', '=', 'classes.id')
                ->where('classes.school_id', $schoolId)
                ->where('class_students.status', 'active')
                ->where('users.role_type', 'student')
                ->whereNotIn('class_students.student_id', $attendedStudents)
                ->select(
                    'users.id as student_id',
                    'users.name as student_name',
                    'users.username',
                    'classes.name as class_name',
                    'classes.grade_level'
                )
                ->orderBy('classes.grade_level')
                ->orderBy('classes.name')
                ->limit(10)
                ->get();

            $alphaCount = DB::table('class_students')
                ->join('classes', 'class_students.class_id', '=', 'classes.id')
                ->where('classes.school_id', $schoolId)
                ->where('class_students.status', 'active')
                ->whereNotIn('class_students.student_id', $attendedStudents)
                ->count();

            return [
                'date' => $targetDate,
                'late' => [
                    'total' => $lateCount,
                    'students' => $lateStudents,
                ],
                'alpha' => [
                    'total' => $alphaCount,
                    'students' => $alphaStudents,
                ],
            ];
        });
    }

    public function getAttendanceAnomalies(User $user, ?string $date = null): array
    {
        $schoolId = $user->school_id;
        $targetDate = $date ? Carbon::parse($date)->toDateString() : Carbon::today()->toDateString();
        $cacheKey = "dashboard_stats_anomalies_{$schoolId}_{$targetDate}";

        return $this->cacheLock->remember($cacheKey, 300, function () use ($schoolId, $targetDate) {
            $manualOverrides = DB::table('attendances')
                ->join('users as students', 'attendances.student_id', '=', 'students.id')
                ->leftJoin('users as recorders', 'attendances.recorded_by', '=', 'recorders.id')
                ->where('attendances.school_id', $schoolId)
                ->whereDate('attendances.attendance_date', $targetDate)
                ->where('attendances.is_manual', true)
                ->select(
                    'attendances.id as attendance_id',
                    'students.id as student_id',
                    'students.name as student_name',
                    'students.username',
                    'recorders.name as recorded_by_name',
                    'attendances.status',
                    'attendances.check_in_time'
                )
                ->orderBy('attendances.updated_at', 'desc')
                ->limit(10)
                ->get()
                ->map(fn ($row) => [
                    'type' => 'manual_override',
                    'message' => "Absensi manual untuk {$row->student_name} ({$row->username})",
                    'attendance_id' => $row->attendance_id,
                    'student_id' => $row->student_id,
                    'recorded_by' => $row->recorded_by_name,
                    'status' => $row->status,
                    'time' => $row->check_in_time,
                ]);

            $multipleScans = DB::table('attendances')
                ->join('users', 'attendances.student_id', '=', 'users.id')
                ->where('attendances.school_id', $schoolId)
                ->whereDate('attendances.attendance_date', $targetDate)
                ->groupBy('attendances.student_id', 'users.name', 'users.username')
                ->havingRaw('count(*) > 1')
                ->select(
                    'attendances.student_id',
                    'users.name as student_name',
                    'users.username',
                    DB::raw('count(*) as scan_count')
                )
                ->orderByDesc('scan_count')
                ->limit(10)
                ->get()
                ->map(fn ($row) => [
                    'type' => 'multiple_scans',
                    'message' => "Siswa {$row->student_name} ({$row->username}) melakukan {$row->scan_count} scan hari ini",
                    'student_id' => $row->student_id,
                    'scan_count' => (int) $row->scan_count,
                ]);

            $items = $manualOverrides->concat($multipleScans)->values();

            return [
                'date' => $targetDate,
                'total' => $items->count(),
                'items' => $items,
            ];
        });
    }
}
