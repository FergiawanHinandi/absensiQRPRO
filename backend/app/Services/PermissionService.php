<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AcademicYear;
use App\Models\ClassStudent;
use App\Models\StudentPermission;
use App\Models\TeacherRole;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * PermissionService - Business Logic for Student Permissions
 *
 * ARCHITECTURE:
 * - All database operations wrapped in transactions
 * - All writes produce audit logs
 * - Multi-tenant isolation via model scopes
 *
 * METHODS:
 * - listForUser()           - Get permissions based on user role
 * - store()                 - Create new permission request
 * - approve()               - Approve permission and generate attendance
 * - reject()                - Reject permission
 * - generateAttendance()    - Create attendance records from approved permission
 */
class PermissionService
{
    /**
     * List permissions based on user role
     *
     * - Homeroom teachers see only their class
     * - Other teachers see their school
     * - Admins see all school permissions
     */
    public function listForUser(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = StudentPermission::with(['student:id,name,username', 'class:id,name'])
            ->where('school_id', $user->school_id);

        // Homeroom teacher filter
        $academicYear = AcademicYear::where('school_id', $user->school_id)
            ->where('is_active', true)
            ->first();

        if ($academicYear) {
            $teacherRole = TeacherRole::where('teacher_id', $user->id)
                ->where('academic_year_id', $academicYear->id)
                ->first();

            if ($teacherRole && $teacherRole->is_homeroom_teacher) {
                $query->where('class_id', $teacherRole->homeroom_class_id);
            }
        }

        // Status filter
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('created_at', 'desc')->paginate(10);
    }

    /**
     * Store a new permission request
     *
     * @param User $user The user creating the permission (teacher or student)
     * @param array $data Validated permission data
     * @param \Illuminate\Http\UploadedFile|null $attachment
     * @return StudentPermission
     */
    public function store(User $user, array $data, $attachment = null): StudentPermission
    {
        return DB::transaction(function () use ($user, $data, $attachment) {
            $isTeacher = $user->hasRole('teacher') || $user->hasRole('homeroom_teacher');
            $studentId = $isTeacher ? $data['student_id'] : $user->id;

            // Handle file upload
            $attachmentPath = null;
            if ($attachment) {
                $attachmentPath = $attachment->store('permissions', 'public');
            }

            // Get student's active class
            $classId = ClassStudent::where('student_id', $studentId)
                ->where('status', 'active')
                ->value('class_id');

            $permission = StudentPermission::create([
                'student_id' => $studentId,
                'school_id' => $user->school_id,
                'class_id' => $classId,
                'type' => $data['type'],
                'reason' => $data['reason'],
                'description' => $data['description'] ?? null,
                'attachment_path' => $attachmentPath,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'status' => $isTeacher ? 'approved' : 'pending',
                'approved_by' => $isTeacher ? $user->id : null,
                'approved_at' => $isTeacher ? now() : null,
            ]);

            Log::info('StudentPermission created', [
                'id' => $permission->id,
                'student_id' => $studentId,
                'created_by' => $user->id,
                'auto_approved' => $isTeacher,
            ]);

            // If teacher created (auto-approved), generate attendance immediately
            if ($isTeacher) {
                $this->generateAttendanceRecords($permission);
            }

            return $permission;
        });
    }

    /**
     * Approve a permission and generate attendance records
     */
    public function approve(StudentPermission $permission, User $approver): StudentPermission
    {
        if (! $permission->isPending()) {
            throw new \InvalidArgumentException('Permission sudah diproses sebelumnya.');
        }

        return DB::transaction(function () use ($permission, $approver) {
            $permission->update([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            $this->generateAttendanceRecords($permission);

            Log::info('StudentPermission approved', [
                'id' => $permission->id,
                'approved_by' => $approver->id,
            ]);

            return $permission->fresh();
        });
    }

    /**
     * Reject a permission
     */
    public function reject(StudentPermission $permission, User $rejector): StudentPermission
    {
        if (! $permission->isPending()) {
            throw new \InvalidArgumentException('Permission sudah diproses sebelumnya.');
        }

        return DB::transaction(function () use ($permission, $rejector) {
            $permission->update([
                'status' => 'rejected',
                'approved_by' => $rejector->id,
                'approved_at' => now(),
            ]);

            Log::info('StudentPermission rejected', [
                'id' => $permission->id,
                'rejected_by' => $rejector->id,
            ]);

            return $permission->fresh();
        });
    }

    /**
     * Generate or update attendance records for approved permission
     *
     * CRITICAL: All writes in transaction, uses Eloquent models
     */
    private function generateAttendanceRecords(StudentPermission $permission): void
    {
        $status = $permission->getAttendanceStatus();
        $notes = "Izin Digital: {$permission->reason}";

        foreach ($permission->getCoveredDates() as $date) {
            $dateString = $date->toDateString();

            // Upsert attendance record
            $attendance = Attendance::where('school_id', $permission->school_id)
                ->where('student_id', $permission->student_id)
                ->whereDate('attendance_date', $dateString)
                ->first();

            if ($attendance) {
                // Update existing
                $attendance->update([
                    'status' => $status,
                    'is_manual' => true,
                    'notes' => $notes,
                ]);
            } else {
                // Create new (using firstOrCreate)
                Attendance::firstOrCreate(
                    [
                        'student_id' => $permission->student_id,
                        'school_id' => $permission->school_id,
                        'attendance_date' => $dateString,
                    ],
                    [
                        'class_id' => $permission->class_id,
                        'status' => $status,
                        'check_in_time' => null,
                        'check_out_time' => null,
                        'is_manual' => true,
                        'notes' => $notes,
                    ]
                );
            }
        }

        Log::info('Attendance records generated from permission', [
            'permission_id' => $permission->id,
            'dates_count' => $permission->getCoveredDates()->count(),
        ]);
    }
}
