<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\ClassStudent;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CRITICAL: Fixed Attendance Service with proper transaction handling
 *
 * FIXES:
 * - Race condition protection
 * - Database transactions
 * - Proper authorization
 * - Performance optimization
 */
class CriticalAttendanceService
{
    public function __construct(
        protected StudentQrService $qrService
    ) {}

    /**
     * CRITICAL: Handle attendance recording with proper transaction and race condition protection
     */
    public function recordByTeacherScan(User $teacher, string $qrToken, ?float $lat, ?float $lng): array
    {
        // CRITICAL: Use database transaction to prevent race conditions
        return DB::transaction(function () use ($teacher, $qrToken, $lat, $lng) {
            try {
                // 1. Verify QR Signature & Payload
                $payload = $this->qrService->verify($qrToken);
            } catch (\Exception $e) {
                $this->logSecurityEvent('signature_failed', $teacher, $qrToken, $e->getMessage());
                throw new \Exception('QR Code tidak valid atau rusak.');
            }

            $studentId = $payload['sid'];
            $schoolId = $payload['sch'];

            // CRITICAL: Ensure QR belongs to same school (AUTHORIZATION CHECK)
            if ($schoolId != $teacher->school_id) {
                $this->logSecurityEvent('school_mismatch', $teacher, $qrToken, "Teacher School: {$teacher->school_id}, QR School: {$schoolId}");
                throw new \Exception('QR Code dari sekolah lain.');
            }

            // 2. Find Active Schedule for Teacher with LOCK to prevent race condition
            $dayOfWeek = now()->dayOfWeek;
            $currentTime = now()->format('H:i:s');

            $schedule = Schedule::with(['class', 'subject'])
                ->where('teacher_id', $teacher->id)
                ->where('day_of_week', $dayOfWeek)
                ->where('start_time', '<=', $currentTime)
                ->where('end_time', '>=', $currentTime)
                ->where('is_active', true)
                ->lockForUpdate() // CRITICAL: Prevent race condition
                ->first();

            if (! $schedule) {
                throw new \Exception('Tidak ada jadwal mengajar aktif saat ini.');
            }

            // 3. CRITICAL: Verify Student belongs to Class with proper authorization
            $student = User::where('id', $studentId)
                ->where('school_id', $teacher->school_id) // CRITICAL: Same school check
                ->where('role_type', 'student')
                ->where('is_active', true)
                ->first();

            if (! $student) {
                $this->logSecurityEvent('student_not_found', $teacher, $qrToken, "Student ID: {$studentId}");
                throw new \Exception('Siswa tidak ditemukan atau tidak aktif.');
            }

            $isStudentInClass = ClassStudent::where('student_id', $studentId)
                ->where('class_id', $schedule->class_id)
                ->where('status', 'active')
                ->exists();

            if (! $isStudentInClass) {
                throw new \Exception('Siswa tidak terdaftar di kelas ini.');
            }

            // 4. CRITICAL: Check Double Attendance with LOCK
            $existingAttendance = Attendance::where('student_id', $studentId)
                ->where('schedule_id', $schedule->id)
                ->whereDate('attendance_date', today())
                ->lockForUpdate() // CRITICAL: Prevent double attendance race condition
                ->first();

            if ($existingAttendance) {
                throw new \Exception('Siswa sudah absen untuk pelajaran ini.');
            }

            // 5. CRITICAL: Record Attendance with all required fields
            $attendance = Attendance::create([
                'school_id' => $teacher->school_id,
                'class_id' => $schedule->class_id,
                'schedule_id' => $schedule->id,
                'student_id' => $studentId,
                'attendance_date' => today(),
                'attendance_type' => 'qr_scan',
                'status' => 'present',
                'check_in_time' => now(),
                'lat_in' => $lat,
                'lng_in' => $lng,
                'recorded_by' => $teacher->id,
                'is_manual' => false,
                'request_id' => request()->header('X-Request-ID', uniqid()),
            ]);

            // 6. CRITICAL: Cache invalidation for performance
            Cache::forget("attendance_summary_{$teacher->school_id}_".today()->format('Y-m-d'));
            Cache::forget("student_attendance_{$studentId}_".today()->format('Y-m-d'));

            return [
                'student_name' => $student->name,
                'class' => $schedule->class->name,
                'subject' => $schedule->subject->name,
                'time' => now()->format('H:i'),
                'status' => 'Berhasil',
                'attendance_id' => $attendance->id,
            ];
        });
    }

    /**
     * CRITICAL: Get class attendance with optimized query (NO N+1)
     */
    public function getClassAttendanceOptimized(int $scheduleId, User $teacher): array
    {
        // CRITICAL: Single query with proper joins to prevent N+1
        $schedule = Schedule::with([
            'class.students' => function ($query) {
                $query->where('is_active', true)
                    ->orderBy('name');
            },
            'subject',
        ])->findOrFail($scheduleId);

        // CRITICAL: Authorization check
        if ($schedule->teacher_id !== $teacher->id || $schedule->class->school_id !== $teacher->school_id) {
            throw new \Exception('Unauthorized access to schedule');
        }

        // CRITICAL: Single query for all attendances (NO N+1)
        $attendances = Attendance::where('schedule_id', $scheduleId)
            ->whereDate('attendance_date', today())
            ->get()
            ->keyBy('student_id');

        // CRITICAL: Optimized mapping without additional queries
        $studentsData = $schedule->class->students->map(function ($student) use ($attendances) {
            $attendance = $attendances->get($student->id);

            return [
                'id' => $student->id,
                'name' => $student->name,
                'username' => $student->username,
                'status' => $attendance ? $attendance->status : 'alpha',
                'check_in_time' => $attendance ? $attendance->check_in_time : null,
                'is_manual' => $attendance ? $attendance->is_manual : false,
            ];
        });

        return [
            'schedule' => [
                'id' => $schedule->id,
                'subject' => $schedule->subject->name,
                'class' => $schedule->class->name,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
            ],
            'students' => $studentsData,
            'summary' => [
                'total' => $studentsData->count(),
                'present' => $studentsData->where('status', 'present')->count(),
                'late' => $studentsData->where('status', 'late')->count(),
                'alpha' => $studentsData->where('status', 'alpha')->count(),
            ],
        ];
    }

    /**
     * CRITICAL: Log security events with proper context
     */
    private function logSecurityEvent(string $type, User $actor, string $token, string $details): void
    {
        Log::channel('attendance')->warning("SECURITY ALERT: {$type}", [
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'actor_school' => $actor->school_id,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'token_snippet' => substr($token, 0, 10).'...',
            'details' => $details,
            'timestamp' => now()->toIso8601String(),
            'request_id' => request()->header('X-Request-ID', uniqid()),
        ]);
    }
}
