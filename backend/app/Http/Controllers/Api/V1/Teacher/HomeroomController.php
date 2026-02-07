<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Controller;
use App\Models\StudentNote;
use App\Models\TeacherRole;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HomeroomController extends Controller
{
    /**
     * Get Homeroom Class Summary
     */
    public function classSummary(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        // 1. Determine Homeroom Status
        $academicYearId = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->value('id') ?? 1;

        $teacherRole = TeacherRole::where('teacher_id', $user->id)
            ->where('academic_year_id', $academicYearId)
            ->first();

        if (! $teacherRole || ! $teacherRole->is_homeroom_teacher) {
            return response()->json([
                'success' => false,
                'message' => 'You are not assigned as a homeroom teacher.',
            ], 403);
        }

        $homeroomClassId = $teacherRole->homeroom_class_id;
        $classInfo = DB::table('classes')->where('id', $homeroomClassId)->select('id', 'name')->first();

        // 2. Student Counts
        $totalStudents = User::whereHas('classStudents', function ($query) use ($homeroomClassId) {
            $query->where('class_id', $homeroomClassId)
                ->where('status', 'active');
        })
            ->where('role_type', 'student')
            ->where('is_active', true)
            ->count();

        // 3. Today's Attendance Stats
        $todayStr = Carbon::now()->toDateString();
        $attendanceCounts = DB::table('attendances')
            ->where('school_id', $schoolId)
            ->where('class_id', $homeroomClassId)
            ->where('date', $todayStr)
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as total'))
            ->pluck('total', 'status');

        $present = $attendanceCounts['present'] ?? 0;
        $late = $attendanceCounts['late'] ?? 0;
        $sick = $attendanceCounts['sick'] ?? 0;
        $permission = $attendanceCounts['permit'] ?? 0;
        $alpha = $attendanceCounts['alpha'] ?? 0;

        $totalAttendanceRecords = $present + $late + $sick + $permission + $alpha;
        $notCheckedIn = max(0, $totalStudents - $totalAttendanceRecords);

        $attendanceRate = $totalStudents > 0
            ? round((($present + $late) / $totalStudents) * 100, 1)
            : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'class_info' => $classInfo,
                'summary' => [
                    'total_students' => $totalStudents,
                    'present_today' => $present + $late,
                    'late_today' => $late,
                    'absent_today' => $sick + $permission + $alpha,
                    'not_checked_in' => $notCheckedIn,
                    'attendance_rate' => $attendanceRate,
                ],
                'details' => [
                    'present' => $present,
                    'late' => $late,
                    'sick' => $sick,
                    'permission' => $permission,
                    'alpha' => $alpha,
                ],
            ],
        ]);
    }

    /**
     * Get Student Notes
     */
    public function getStudentNotes(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        // 1. Validate Homeroom Access
        $academicYearId = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->value('id') ?? 1;

        $teacherRole = TeacherRole::where('teacher_id', $user->id)
            ->where('academic_year_id', $academicYearId)
            ->first();

        if (! $teacherRole || ! $teacherRole->is_homeroom_teacher) {
            return response()->json([
                'success' => false,
                'message' => 'You are not assigned as a homeroom teacher.',
            ], 403);
        }

        $homeroomClassId = $teacherRole->homeroom_class_id;

        // 2. Fetch Notes
        // Optional: Filter by specific student
        $studentId = $request->input('student_id');

        $query = StudentNote::with(['student:id,name,username', 'teacher:id,name'])
            ->where('school_id', $schoolId)
            ->where('teacher_id', $user->id); // Only own notes

        if ($studentId) {
            // Verify student belongs to homeroom class
            $isStudentInClass = DB::table('class_students')
                ->where('class_id', $homeroomClassId)
                ->where('student_id', $studentId)
                ->where('status', 'active')
                ->exists();

            if (! $isStudentInClass) {
                return response()->json([
                    'success' => false,
                    'message' => 'Student does not belong to your homeroom class.',
                ], 403);
            }

            $query->where('student_id', $studentId);
        } else {
            // Filter by all students in homeroom
            $query->whereHas('student.classStudents', function ($q) use ($homeroomClassId) {
                $q->where('class_id', $homeroomClassId)
                    ->where('status', 'active');
            });
        }

        $notes = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $notes,
        ]);
    }

    /**
     * Create Student Note
     */
    public function storeStudentNote(Request $request)
    {
        $request->validate([
            'student_id' => 'required|exists:users,id',
            'note' => 'required|string',
            'type' => 'nullable|string|in:general,academic,behavioral',
        ]);

        $user = $request->user();
        $schoolId = $user->school_id;
        $studentId = $request->input('student_id');

        // 1. Validate Homeroom Access
        $academicYearId = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->value('id') ?? 1;

        $teacherRole = TeacherRole::where('teacher_id', $user->id)
            ->where('academic_year_id', $academicYearId)
            ->first();

        if (! $teacherRole || ! $teacherRole->is_homeroom_teacher) {
            return response()->json([
                'success' => false,
                'message' => 'You are not assigned as a homeroom teacher.',
            ], 403);
        }

        $homeroomClassId = $teacherRole->homeroom_class_id;

        // 2. Verify Student in Homeroom
        $isStudentInClass = DB::table('class_students')
            ->where('class_id', $homeroomClassId)
            ->where('student_id', $studentId)
            ->where('status', 'active')
            ->exists();

        if (! $isStudentInClass) {
            return response()->json([
                'success' => false,
                'message' => 'Student does not belong to your homeroom class.',
            ], 403);
        }

        // 3. Create Note
        $note = StudentNote::create([
            'school_id' => $schoolId,
            'student_id' => $studentId,
            'teacher_id' => $user->id,
            'note' => $request->input('note'),
            'type' => $request->input('type', 'general'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Note created successfully.',
            'data' => $note,
        ]);
    }
}
