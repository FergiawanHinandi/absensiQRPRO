<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TeacherRole;
use App\Models\TeacherSubject;
use Illuminate\Http\Request;

class TeacherProfileController extends Controller
{
    /**
     * Get teacher profile with role information
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request)
    {
        $user = $request->user();

        // Pastikan user adalah guru
        if ($user->role_type !== 'teacher') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only teachers can access this endpoint.',
            ], 403);
        }

        // Get current academic year (simplified - should get from settings)
        // Get current academic year
        $currentAcademicYearId = \Illuminate\Support\Facades\DB::table('academic_years')
            ->where('school_id', $user->school_id)
            ->where('is_active', true)
            ->value('id') ?? 1;

        // Get teacher role info
        $teacherRole = TeacherRole::where('teacher_id', $user->id)
            ->where('academic_year_id', $currentAcademicYearId)
            ->with('homeroomClass')
            ->first();

        // Get teacher subjects
        $teacherSubjects = TeacherSubject::where('teacher_id', $user->id)
            ->where('academic_year_id', $currentAcademicYearId)
            ->with(['subject', 'class'])
            ->get();

        // Group subjects by subject name
        $subjectsGrouped = $teacherSubjects->groupBy('subject_id')->map(function ($items) {
            $subject = $items->first()->subject;

            return [
                'id' => $subject->id,
                'name' => $subject->name,
                'classes' => $items->map(function ($item) {
                    return [
                        'id' => $item->class->id,
                        'name' => $item->class->name,
                    ];
                })->values(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role_type' => $user->role_type,
                ],
                'is_homeroom_teacher' => $teacherRole ? $teacherRole->is_homeroom_teacher : false,
                'homeroom_class' => $teacherRole && $teacherRole->is_homeroom_teacher ? [
                    'id' => $teacherRole->homeroomClass->id,
                    'name' => $teacherRole->homeroomClass->name,
                ] : null,
                'subjects' => $subjectsGrouped,
                'total_classes' => $teacherSubjects->unique('class_id')->count(),
            ],
        ]);
    }
}
