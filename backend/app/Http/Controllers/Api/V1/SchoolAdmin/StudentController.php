<?php

namespace App\Http\Controllers\Api\V1\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Common\ImportFileRequest;
use App\Http\Requests\SchoolAdmin\StoreStudentRequest;
use App\Http\Requests\SchoolAdmin\UpdateStudentMutationRequest;
use App\Http\Requests\SchoolAdmin\UpdateStudentPlacementRequest;
use App\Http\Requests\SchoolAdmin\UpdateStudentRequest;
use App\Models\User;
use App\Services\SchoolAdmin\StudentService;
use App\Traits\HasSchoolLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentController extends Controller
{
    use HasSchoolLimits;

    protected $studentService;

    public function __construct(StudentService $studentService)
    {
        $this->studentService = $studentService;
    }

    /**
     * Import Students from CSV
     */
    public function import(ImportFileRequest $request)
    {
        try {
            $count = $this->studentService->import($request->file('file'), $request->user()->school_id);
            \App\Events\StudentUpdated::dispatch(null, $request->user()->school_id);

            return response()->json([
                'success' => true,
                'message' => "Berhasil mengimpor {$count} siswa.",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get Students
     * OPTIMIZED: Added field selection to eager loading
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $perPage = $request->input('per_page', 20);
        $search = $request->input('search');
        $classId = $request->input('class_id');

        // OPTIMIZATION: Add field selection to reduce memory usage
        $query = User::where('school_id', $schoolId)
            ->where('role_type', 'student')
            ->with(['studentClass:id,student_id,class_id,status']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('nis', 'ILIKE', "%{$search}%")
                    ->orWhere('nisn', 'ILIKE', "%{$search}%");
            });
        }

        if ($classId) {
            $query->where('class_id', $classId);
        }

        $students = $query->orderBy('name')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $students,
        ]);
    }

    /**
     * Store Student
     */
    public function store(StoreStudentRequest $request)
    {
        try {
            $student = $this->studentService->store($request->validated(), $request->user()->school_id);
            \App\Events\StudentUpdated::dispatch($student, $request->user()->school_id);

            return response()->json([
                'success' => true,
                'message' => 'Siswa berhasil ditambahkan',
                'data' => $student,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show Student Details - FIXED: Authorization before data loading
     */
    public function show(Request $request, int $studentId)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        // CRITICAL: Check authorization BEFORE loading data
        $this->authorize('viewAny', User::class);

        // CRITICAL: Validate student belongs to same school BEFORE loading sensitive data
        $studentExists = User::where('school_id', $schoolId)
            ->where('role_type', 'student')
            ->where('id', $studentId)
            ->exists();

        if (! $studentExists) {
            return response()->json([
                'success' => false,
                'message' => 'Siswa tidak ditemukan atau bukan milik sekolah Anda.',
            ], 404);
        }

        // CRITICAL: Now safe to load data with eager loading (prevent N+1)
        $student = User::with([
            'studentClass:id,student_id,class_id,status',
            'studentClass.class_model:id,name,grade_level',
            'profile:user_id,nisn,phone,address,birth_date,gender',
            'attendances' => function ($query) {
                $query->select('id', 'student_id', 'attendance_date', 'status', 'check_in_time')
                    ->latest()
                    ->limit(5);
            },
        ])
            ->where('school_id', $schoolId)
            ->where('role_type', 'student')
            ->where('id', $studentId)
            ->first();

        // CRITICAL: Final authorization check on loaded model
        $this->authorize('view', $student);

        return response()->json([
            'success' => true,
            'data' => $student,
        ]);
    }

    /**
     * Update Student
     */
    public function update(UpdateStudentRequest $request, int $studentId)
    {
        try {
            // Get student first to authorize
            $student = User::where('id', $studentId)
                ->where('role_type', 'student')
                ->firstOrFail();

            // Policy-based authorization check
            $this->authorize('update', $student);

            $student = $this->studentService->update($studentId, $request->validated(), $request->user()->school_id);
            \App\Events\StudentUpdated::dispatch($student, $request->user()->school_id);

            return response()->json([
                'success' => true,
                'message' => 'Data siswa berhasil diperbarui',
                'data' => $student,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Student Placements - FIXED: N+1 Query with Eager Loading
     */
    public function placements(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $search = $request->input('search');
        $classId = $request->input('class_id');

        // CRITICAL: Use Eloquent with eager loading instead of raw queries
        $query = User::with([
            'classStudent:id,student_id,class_id,status',
            'classStudent.class_model:id,name,grade_level',
            'profile:user_id,nisn,phone,address',
        ])
            ->where('school_id', $schoolId)
            ->where('role_type', 'student')
            ->where('is_active', true);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('username', 'ILIKE', "%{$search}%")
                    ->orWhere('email', 'ILIKE', "%{$search}%");
            });
        }

        if ($classId) {
            $query->whereHas('classStudent', function ($q) use ($classId) {
                $q->where('class_id', $classId)->where('status', 'active');
            });
        }

        // CRITICAL: Use pagination to prevent memory issues
        $students = $query->orderBy('name')
            ->paginate(50) // Limit to 50 per page
            ->through(function ($student) {
                return [
                    'id' => $student->id,
                    'name' => $student->name,
                    'username' => $student->username,
                    'email' => $student->email,
                    'nisn' => $student->profile?->nisn,
                    'class_id' => $student->classStudent?->class_model?->id,
                    'class_name' => $student->classStudent?->class_model?->name,
                    'class_status' => $student->classStudent?->status ?? 'unassigned',
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'students' => $students->items(),
                'pagination' => [
                    'current_page' => $students->currentPage(),
                    'last_page' => $students->lastPage(),
                    'per_page' => $students->perPage(),
                    'total' => $students->total(),
                ],
            ],
        ]);
    }

    /**
     * Update Student Placement
     */
    public function updatePlacement(UpdateStudentPlacementRequest $request, int $studentId)
    {
        try {
            $this->studentService->updatePlacement($studentId, $request->validated()['class_id'], $request->user()->school_id);

            return response()->json([
                'success' => true,
                'message' => 'Penempatan kelas berhasil diperbarui',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get Student Mutations
     */
    public function mutations(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $search = $request->input('search');
        $status = $request->input('status');

        $query = DB::table('class_students')
            ->join('users as students', 'class_students.student_id', '=', 'students.id')
            ->join('classes', 'class_students.class_id', '=', 'classes.id')
            ->where('students.school_id', $schoolId)
            ->where('students.role_type', 'student')
            ->whereIn('class_students.status', ['moved', 'graduated', 'dropped']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('students.name', 'ILIKE', "%{$search}%")
                    ->orWhere('students.username', 'ILIKE', "%{$search}%")
                    ->orWhere('students.email', 'ILIKE', "%{$search}%");
            });
        }

        if ($status && in_array($status, ['moved', 'graduated', 'dropped'], true)) {
            $query->where('class_students.status', $status);
        }

        $mutations = $query
            ->select(
                'class_students.id',
                'class_students.student_id',
                'students.name as student_name',
                'students.username',
                'classes.name as class_name',
                'class_students.status',
                'class_students.updated_at'
            )
            ->orderByDesc('class_students.updated_at')
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'student_id' => $item->student_id,
                'student_name' => $item->student_name,
                'username' => $item->username,
                'class_name' => $item->class_name,
                'status' => $item->status,
                'updated_at' => $item->updated_at,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $mutations->count(),
                'mutations' => $mutations,
            ],
        ]);
    }

    /**
     * Update Mutation Status
     */
    public function updateMutation(UpdateStudentMutationRequest $request, int $studentId)
    {
        try {
            $this->studentService->updateMutation($studentId, $request->validated()['status'], $request->user()->school_id);

            return response()->json([
                'success' => true,
                'message' => 'Status mutasi siswa berhasil diperbarui',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Parents
     */
    public function parents(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        $parents = User::where('school_id', $schoolId)
            ->where('role_type', 'parent')
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'username' => $p->username,
                'email' => $p->email,
                'is_active' => (bool) $p->is_active,
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $parents->count(),
                'parents' => $parents,
            ],
        ]);
    }
}
