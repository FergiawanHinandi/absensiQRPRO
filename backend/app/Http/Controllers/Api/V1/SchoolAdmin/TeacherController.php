<?php

namespace App\Http\Controllers\Api\V1\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Common\ImportFileRequest;
use App\Http\Requests\SchoolAdmin\StoreTeacherRequest;
use App\Http\Requests\SchoolAdmin\UpdateTeacherRequest;
use App\Http\Requests\SchoolAdmin\UpdateTeacherStatusRequest;
use App\Http\Requests\StoreHomeroomTeacherRequest;
use App\Http\Requests\StoreTeacherAssignmentRequest;
use App\Models\School;
use App\Models\TeacherRole;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\SchoolAdmin\TeacherService;
use App\Traits\HasSchoolLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeacherController extends Controller
{
    use HasSchoolLimits;

    protected $teacherService;

    public function __construct(TeacherService $teacherService)
    {
        $this->teacherService = $teacherService;
    }

    /**
     * Get all teachers for the current admin's school
     *
     * BE-10 FIX: Gunakan Eloquent User:: bukan DB::table() agar semua proteksi
     * model aktif (BelongsToSchool trait, SoftDeletes, observers, scopes).
     *
     * A3-H3 FIX: Join ke user_profiles agar search NIP berfungsi.
     * Kolom NIP ada di tabel user_profiles, bukan di tabel users.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $perPage = $request->input('per_page', 20);
        $search = $request->input('search');

        // A3-H3 FIX: Join ke user_profiles untuk mendapatkan NIP
        $query = User::where('users.school_id', $schoolId)
            ->whereIn('users.role_type', ['teacher', 'homeroom_teacher'])
            ->leftJoin('user_profiles', 'users.id', '=', 'user_profiles.user_id')
            ->select([
                'users.id',
                'users.school_id',
                'users.name',
                'users.email',
                'users.username',
                'users.role_type',
                'users.is_active',
                'users.last_login_at',
                'users.created_at',
                'user_profiles.nip',         // A3-H3 FIX: ambil NIP dari user_profiles
                'user_profiles.phone',
                'user_profiles.address',
            ])
            ->with([
                'teacherDevices:id,teacher_id,device_name,is_active',
            ]);

        if ($search) {
            // ILIKE adalah operator PostgreSQL (case-insensitive LIKE)
            // A3-H3 FIX: Tambahkan search berdasarkan user_profiles.nip
            $query->where(function ($q) use ($search) {
                $q->where('users.name', 'ILIKE', "%{$search}%")
                    ->orWhere('users.email', 'ILIKE', "%{$search}%")
                    ->orWhere('users.username', 'ILIKE', "%{$search}%")
                    ->orWhere('user_profiles.nip', 'ILIKE', "%{$search}%");  // NIP search
            });
        }

        $teachers = $query->orderBy('users.created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $teachers,
        ]);
    }

    /**
     * Store a new teacher
     */
    public function store(StoreTeacherRequest $request)
    {
        try {
            $teacher = $this->teacherService->store($request->validated(), $request->user()->school_id);

            return response()->json([
                'success' => true,
                'message' => 'Guru berhasil ditambahkan',
                'data' => $teacher,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show teacher details
     */
    public function show(Request $request, int $teacherId)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        $teacher = User::with(['profile:user_id,phone,nip'])->where('id', $teacherId)
            ->where('school_id', $schoolId)
            ->whereIn('role_type', ['teacher', 'homeroom_teacher'])
            ->first();

        if (!$teacher) {
            return response()->json([
                'success' => false,
                'message' => 'Guru tidak ditemukan',
            ], 404);
        }

        // Load assignments count
        $assignmentsCount = TeacherSubject::where('teacher_id', $teacherId)->count();

        // Load homeroom info
        $homeroomClass = TeacherRole::where('teacher_id', $teacherId)
            ->where('is_homeroom_teacher', true)
            ->with('homeroomClass:id,name')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'id'               => $teacher->id,
                'name'             => $teacher->name,
                'email'            => $teacher->email,
                'nip'              => $teacher->profile?->nip ?? null,
                'phone'            => $teacher->profile?->phone ?? null,
                'role_type'        => $teacher->role_type,
                'is_active'        => (bool) $teacher->is_active,
                'assignments_count'=> $assignmentsCount,
                'homeroom_class'   => $homeroomClass?->homeroomClass?->name,
                'created_at'       => $teacher->created_at,
            ],
        ]);
    }

    /**
     * Delete a teacher
     */
    public function destroy(Request $request, int $teacherId)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        $teacher = User::where('id', $teacherId)
            ->where('school_id', $schoolId)
            ->whereIn('role_type', ['teacher', 'homeroom_teacher'])
            ->firstOrFail();

        // Policy-based authorization check
        $this->authorize('delete', $teacher);

        // Check if teacher has active schedules
        $activeSchedules = \App\Models\Schedule::where('teacher_id', $teacherId)
            ->where('is_active', true)
            ->count();

        if ($activeSchedules > 0) {
            return response()->json([
                'success' => false,
                'message' => "Guru masih memiliki {$activeSchedules} jadwal aktif. Nonaktifkan jadwal terlebih dahulu.",
            ], 422);
        }

        $teacher->delete();

        return response()->json([
            'success' => true,
            'message' => 'Guru berhasil dihapus',
        ]);
    }

    /**
     * Import Teachers from CSV
     */
    public function import(ImportFileRequest $request)
    {
        try {
            $count = $this->teacherService->import($request->file('file'), $request->user()->school_id);

            return response()->json([
                'success' => true,
                'message' => "Berhasil mengimpor {$count} guru.",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Update teacher details
     */
    public function update(UpdateTeacherRequest $request, int $teacherId)
    {
        try {
            // Get teacher first to authorize
            $teacher = User::where('id', $teacherId)
                ->whereIn('role_type', ['teacher', 'homeroom_teacher'])
                ->firstOrFail();

            // Policy-based authorization check
            $this->authorize('update', $teacher);

            $teacher = $this->teacherService->update($teacherId, $request->validated(), $request->user()->school_id);

            return response()->json([
                'success' => true,
                'message' => 'Data guru berhasil diperbarui',
                'data' => $teacher,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update teacher status (active/inactive)
     */
    public function updateStatus(UpdateTeacherStatusRequest $request, $teacherId)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        $teacher = User::where('id', $teacherId)
            ->where('school_id', $schoolId)
            ->where('role_type', 'teacher')
            ->firstOrFail();

        // Policy-based authorization check
        $this->authorize('update', $teacher);

        $teacher->is_active = ! $teacher->is_active;
        $teacher->save();

        return response()->json([
            'success' => true,
            'message' => $teacher->is_active ? 'Guru diaktifkan' : 'Guru dinonaktifkan',
            'data' => ['is_active' => $teacher->is_active],
        ]);
    }

    /**
     * Set Homeroom Teacher (Wali Kelas)
     */
    public function setHomeroom(StoreHomeroomTeacherRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        $teacher = User::where('id', $validated['teacher_id'])
            ->where('school_id', $user->school_id)
            ->firstOrFail();

        TeacherRole::updateOrCreate(
            [
                'homeroom_class_id' => $validated['class_id'],
                'academic_year_id'  => $validated['academic_year_id'],
            ],
            [
                'teacher_id'          => $teacher->id,
                'is_homeroom_teacher' => true,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Wali kelas berhasil diatur',
        ]);
    }

    /**
     * Get Teacher Assignments (Guru Mapel) - OPTIMIZED: Pagination + Lazy Loading
     */
    public function assignments(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        // CRITICAL: Add pagination parameters
        $perPage = min($request->input('per_page', 20), 100); // Max 100 per page
        $search = $request->input('search');
        $subjectId = $request->input('subject_id');
        $classId = $request->input('class_id');
        $academicYearId = $request->input('academic_year_id');

        // CRITICAL: Use query builder with pagination instead of get()
        $query = TeacherSubject::whereHas('teacher', function ($q) use ($schoolId) {
            $q->where('school_id', $schoolId);
        });

        // CRITICAL: Add search filters to reduce dataset
        if ($search) {
            $query->whereHas('teacher', function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%");
            });
        }

        if ($subjectId) {
            $query->where('subject_id', $subjectId);
        }

        if ($classId) {
            $query->where('class_id', $classId);
        }

        if ($academicYearId) {
            $query->where('academic_year_id', $academicYearId);
        }

        // CRITICAL: Use pagination with optimized eager loading
        $assignments = $query->with([
            'teacher:id,name,email', // Only load required fields
            'subject:id,name,code',
            'class:id,name,grade_level',
            'academicYear:id,name,start_date,end_date',
        ])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // CRITICAL: Transform data efficiently without additional queries
        $assignments->getCollection()->transform(function ($assignment) {
            return [
                'id' => $assignment->id,
                'teacher' => [
                    'id' => $assignment->teacher->id,
                    'name' => $assignment->teacher->name,
                    'email' => $assignment->teacher->email,
                ],
                'subject' => [
                    'id' => $assignment->subject->id,
                    'name' => $assignment->subject->name,
                    'code' => $assignment->subject->code ?? null,
                ],
                'class' => [
                    'id' => $assignment->class->id,
                    'name' => $assignment->class->name,
                    'grade_level' => $assignment->class->grade_level,
                ],
                'academic_year' => [
                    'id' => $assignment->academicYear->id,
                    'name' => $assignment->academicYear->name,
                ],
                'created_at' => $assignment->created_at,
                'updated_at' => $assignment->updated_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'assignments' => $assignments->items(),
                'pagination' => [
                    'current_page' => $assignments->currentPage(),
                    'last_page' => $assignments->lastPage(),
                    'per_page' => $assignments->perPage(),
                    'total' => $assignments->total(),
                    'from' => $assignments->firstItem(),
                    'to' => $assignments->lastItem(),
                ],
            ],
        ]);
    }

    /**
     * ALTERNATIVE: Lazy Loading for Large Datasets (Export/Bulk Operations)
     */
    public function assignmentsLazy(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        // CRITICAL: Use lazy() for memory-efficient iteration over large datasets
        $assignments = TeacherSubject::whereHas('teacher', function ($q) use ($schoolId) {
            $q->where('school_id', $schoolId);
        })
            ->with([
                'teacher:id,name',
                'subject:id,name',
                'class:id,name',
            ])
            ->lazy(100); // Process 100 records at a time

        $result = [];

        // CRITICAL: Process in chunks to prevent memory overflow
        foreach ($assignments as $assignment) {
            $result[] = [
                'teacher_name' => $assignment->teacher->name,
                'subject_name' => $assignment->subject->name,
                'class_name' => $assignment->class->name,
            ];

            // CRITICAL: Optional memory management for very large datasets
            if (count($result) >= 1000) {
                // Process batch and clear memory
                // yield $result; // If using generator pattern
                break;
            }
        }

        return response()->json([
            'success' => true,
            'data' => $result,
            'memory_usage' => memory_get_usage(true) / 1024 / 1024 .' MB',
        ]);
    }

    /**
     * Store Teacher Assignment
     */
    public function storeAssignment(StoreTeacherAssignmentRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        $academicYearId = $validated['academic_year_id'] ?? DB::table('academic_years')
            ->where('school_id', $user->school_id)
            ->where('is_active', true)
            ->value('id');

        if (! $academicYearId) {
            return response()->json([
                'success' => false,
                'message' => 'Tahun ajaran aktif belum diset.',
            ], 422);
        }

        $teacher = User::where('id', $validated['teacher_id'])
            ->where('school_id', $user->school_id)
            ->firstOrFail();

        $assignment = TeacherSubject::updateOrCreate(
            [
                'teacher_id' => $teacher->id,
                'subject_id' => $validated['subject_id'],
                'class_id' => $validated['class_id'],
                'academic_year_id' => $academicYearId,
            ],
            []
        );

        return response()->json([
            'success' => true,
            'message' => 'Penugasan guru berhasil disimpan',
            'data' => $assignment,
        ]);
    }

    /**
     * Delete Teacher Assignment
     */
    public function destroyAssignment(Request $request, $assignmentId)
    {
        $assignment = TeacherSubject::findOrFail($assignmentId);

        if ($assignment->teacher->school_id !== $request->user()->school_id) {
            abort(403, 'Unauthorized action.');
        }

        $assignment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Penugasan berhasil dihapus',
        ]);
    }
}
