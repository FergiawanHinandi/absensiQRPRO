<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Permission\StorePermissionRequest;
use App\Http\Requests\Permission\UpdatePermissionStatusRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PermissionController extends Controller
{
    /**
     * List Permissions (Homeroom Teacher View)
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Query permissions based on user role
        // For Teacher/Homeroom: See their class or school
        // For now, let's assume filtering by school_id provided in query or user's school

        $query = DB::table('student_permissions')
            ->join('users', 'student_permissions.student_id', '=', 'users.id')
            ->leftJoin('classes', 'student_permissions.class_id', '=', 'classes.id')
            ->select(
                'student_permissions.*',
                'users.name as student_name',
                'users.username as student_nis',
                'classes.name as class_name'
            )
            ->where('student_permissions.school_id', $user->school_id);

        // Filter for Homeroom Teacher
        $currentDate = Carbon::now(); // Get academic year
        $academicYearId = DB::table('academic_years')
            ->where('school_id', $user->school_id)
            ->where('is_active', true)
            ->value('id') ?? 1;

        $teacherRole = \App\Models\TeacherRole::where('teacher_id', $user->id)
            ->where('academic_year_id', $academicYearId)
            ->first();

        if ($teacherRole && $teacherRole->is_homeroom_teacher) {
            $query->where('student_permissions.class_id', $teacherRole->homeroom_class_id);
        }

        if ($request->status) {
            $query->where('student_permissions.status', $request->status);
        }

        // Pagination
        $permissions = $query->orderBy('student_permissions.created_at', 'desc')->paginate(10);

        return response()->json(['success' => true, 'data' => $permissions]);
    }

    /**
     * Store Permission (Student Request OR Teacher Input)
     */
    public function store(StorePermissionRequest $request)
    {
        $user = $request->user();
        $isTeacher = $user->hasRole('teacher') || $user->hasRole('homeroom_teacher');

        $validated = $request->validated();

        $studentId = $isTeacher ? $validated['student_id'] : $user->id;

        // Handling Attachment
        $path = null;
        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store('permissions', 'public');
        }

        // Get class info
        $classId = DB::table('class_students')
            ->where('student_id', $studentId)
            ->where('status', 'active')
            ->value('class_id');

        $status = $isTeacher ? 'approved' : 'pending';
        $approvedBy = $isTeacher ? $user->id : null;
        $approvedAt = $isTeacher ? now() : null;

        $permissionId = DB::table('student_permissions')->insertGetId([
            'student_id' => $studentId,
            'school_id' => $user->school_id, // Asumsi guru & siswa satu sekolah
            'class_id' => $classId,
            'type' => $validated['type'],
            'reason' => $validated['reason'],
            'description' => $validated['description'] ?? null,
            'attachment_path' => $path,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'status' => $status,
            'approved_by' => $approvedBy,
            'approved_at' => $approvedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // If added by teacher, generate attendance records immediately
        if ($isTeacher) {
            $this->generateAttendanceForPermission($permissionId);
        }

        return response()->json(['success' => true, 'message' => 'Izin berhasil disimpan', 'id' => $permissionId]);
    }

    /**
     * Helper to generate attendance from permission
     */
    private function generateAttendanceForPermission($permissionId)
    {
        $permission = DB::table('student_permissions')->where('id', $permissionId)->first();
        if (! $permission) {
            return;
        }

        $start = Carbon::parse($permission->start_date);
        $end = Carbon::parse($permission->end_date);
        $attendStatus = $permission->type === 'sick' ? 'sick' : 'permit';

        while ($start->lte($end)) {
            $exists = DB::table('attendances')
                ->where('school_id', $permission->school_id)
                ->where('student_id', $permission->student_id)
                ->where('date', $start->toDateString())
                ->exists();

            if ($exists) {
                DB::table('attendances')
                    ->where('school_id', $permission->school_id)
                    ->where('student_id', $permission->student_id)
                    ->where('date', $start->toDateString())
                    ->update([
                        'status' => $attendStatus,
                        'is_manual' => true,
                        'notes' => 'Izin Digital: '.$permission->reason,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('attendances')->insert([
                    'student_id' => $permission->student_id,
                    'school_id' => $permission->school_id,
                    'class_id' => $permission->class_id,
                    'date' => $start->toDateString(),
                    'status' => $attendStatus,
                    'check_in_time' => null,
                    'check_out_time' => null,
                    'is_manual' => true,
                    'notes' => 'Izin Digital: '.$permission->reason,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $start->addDay();
        }
    }

    /**
     * Approve/Reject Permission (Teacher Action)
     */
    public function updateStatus(UpdatePermissionStatusRequest $request, $id)
    {
        $validated = $request->validated();

        $permission = DB::table('student_permissions')->where('id', $id)->first();
        if (! $permission) {
            return response()->json(['success' => false, 'message' => 'Data tidak ditemukan'], 404);
        }

        DB::beginTransaction();
        try {
            // 1. Update Permission Status
            DB::table('student_permissions')->where('id', $id)->update([
                'status' => $validated['status'],
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'updated_at' => now(),
            ]);

            // 2. If Approved, Generate Attendance Records
            if ($validated['status'] === 'approved') {
                $start = Carbon::parse($permission->start_date);
                $end = Carbon::parse($permission->end_date);

                // Mapping status permission -> status attendance
                $attendStatus = $permission->type === 'sick' ? 'sick' : 'permit';

                while ($start->lte($end)) {
                    // Check if weekend? Optional. For now, just insert.

                    // Upsert Attendance
                    // Check if exists
                    $exists = DB::table('attendances')
                        ->where('school_id', $permission->school_id)
                        ->where('student_id', $permission->student_id)
                        ->where('date', $start->toDateString())
                        ->exists();

                    if ($exists) {
                        DB::table('attendances')
                            ->where('school_id', $permission->school_id)
                            ->where('student_id', $permission->student_id)
                            ->where('date', $start->toDateString())
                            ->update([
                                'status' => $attendStatus,
                                'is_manual' => true,
                                'notes' => 'Izin Digital: '.$permission->reason,
                                'updated_at' => now(),
                            ]);
                    } else {
                        DB::table('attendances')->insert([
                            'student_id' => $permission->student_id,
                            'school_id' => $permission->school_id,
                            'class_id' => $permission->class_id,
                            'date' => $start->toDateString(),
                            'status' => $attendStatus,
                            'check_in_time' => null, // No checkin time for permit
                            'check_out_time' => null,
                            'is_manual' => true,
                            'notes' => 'Izin Digital: '.$permission->reason,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $start->addDay();
                }
            }

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Status izin diperbarui']);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['success' => false, 'message' => 'Gagal memproses: '.$e->getMessage()], 500);
        }
    }
}
