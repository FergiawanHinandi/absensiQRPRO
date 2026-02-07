<?php

namespace App\Http\Controllers\Api\V1\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use App\Models\TeacherSubject;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeacherSubjectController extends Controller
{
    /**
     * Display a listing of teacher assignments.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $assignments = TeacherSubject::with(['teacher:id,name', 'subject:id,name,code', 'class:id,name'])
            ->whereHas('teacher', function ($q) use ($user) {
                $q->where('school_id', $user->school_id);
            })
            ->when($request->teacher_id, function ($q, $id) {
                $q->where('teacher_id', $id);
            })
            ->when($request->subject_id, function ($q, $id) {
                $q->where('subject_id', $id);
            })
            ->paginate($request->get('per_page', 20));

        return response()->success($assignments);
    }

    /**
     * Assign a subject to a teacher.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'teacher_id' => [
                'required',
                Rule::exists('users', 'id')->where(function ($query) use ($user) {
                    $query->where('school_id', $user->school_id)
                        ->whereIn('role_type', ['teacher', 'school_admin']); // Allow admin to teach too? Usually just teacher.
                }),
            ],
            'subject_id' => [
                'required',
                Rule::exists('subjects', 'id')->where(function ($query) use ($user) {
                    $query->where('school_id', $user->school_id);
                }),
            ],
            'class_id' => [
                'nullable',
                Rule::exists('classes', 'id')->where(function ($query) use ($user) {
                    $query->where('school_id', $user->school_id);
                }),
            ],
            'academic_year_id' => 'nullable|exists:academic_years,id',
        ]);

        // Check for duplicates
        $exists = TeacherSubject::where('teacher_id', $validated['teacher_id'])
            ->where('subject_id', $validated['subject_id'])
            ->when(isset($validated['class_id']), function ($q) use ($validated) {
                $q->where('class_id', $validated['class_id']);
            }, function ($q) {
                $q->whereNull('class_id');
            })
            ->exists();

        if ($exists) {
            return response()->error('Teacher already assigned to this subject (and class if specified)', 409);
        }

        $assignment = TeacherSubject::create($validated);

        return response()->success($assignment, 'Teacher assigned successfully', 201);
    }

    /**
     * Remove an assignment.
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();

        $assignment = TeacherSubject::where('id', $id)
            ->whereHas('teacher', function ($q) use ($user) {
                $q->where('school_id', $user->school_id);
            })
            ->firstOrFail();

        $assignment->delete();

        return response()->success(null, 'Assignment removed successfully');
    }
}
