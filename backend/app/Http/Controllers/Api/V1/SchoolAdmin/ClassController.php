<?php

namespace App\Http\Controllers\Api\V1\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SchoolAdmin\StoreClassRequest;
use App\Http\Requests\SchoolAdmin\UpdateClassRequest;
use App\Http\Requests\SchoolAdmin\UpdateClassStatusRequest;
use App\Services\SchoolAdmin\ClassService;
use App\Traits\HasSchoolLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClassController extends Controller
{
    use HasSchoolLimits;

    protected $classService;

    public function __construct(ClassService $classService)
    {
        $this->classService = $classService;
    }

    /**
     * Get Classes
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        // SECURITY: Always filter by school_id - CRITICAL for tenant isolation
        $classes = DB::table('classes')
            ->leftJoin('users as homeroom', 'classes.homeroom_teacher_id', '=', 'homeroom.id')
            ->leftJoin('class_students', function ($join) {
                $join->on('class_students.class_id', '=', 'classes.id')
                    ->where('class_students.status', '=', 'active');
            })
            ->where('classes.school_id', $schoolId) // MANDATORY school_id filter
            ->where('homeroom.school_id', $schoolId) // Join table also filtered
            ->groupBy(
                'classes.id',
                'classes.name',
                'classes.grade_level',
                'classes.academic_year_id',
                'classes.max_students',
                'classes.classroom',
                'classes.is_active',
                'classes.homeroom_teacher_id',
                'homeroom.name'
            )
            ->select(
                'classes.id',
                'classes.name',
                'classes.grade_level',
                'classes.academic_year_id',
                'classes.max_students',
                'classes.classroom',
                'classes.is_active',
                'classes.homeroom_teacher_id',
                'homeroom.name as homeroom_teacher',
                DB::raw('count(class_students.id) as total_students')
            )
            ->orderBy('classes.grade_level')
            ->orderBy('classes.name')
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'grade_level' => $item->grade_level,
                'academic_year_id' => $item->academic_year_id,
                'max_students' => $item->max_students,
                'classroom' => $item->classroom,
                'is_active' => (bool) $item->is_active,
                'homeroom_teacher_id' => $item->homeroom_teacher_id,
                'homeroom_teacher' => $item->homeroom_teacher,
                'total_students' => (int) $item->total_students,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $classes->count(),
                'classes' => $classes,
            ],
        ]);
    }

    /**
     * Store Class
     */
    public function store(StoreClassRequest $request)
    {
        try {
            // SECURITY: Pass user's school_id to ensure tenant isolation
            $class = $this->classService->store($request->validated(), $request->user()->school_id);

            return response()->json([
                'success' => true,
                'message' => 'Kelas berhasil ditambahkan',
                'data' => $class,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Update Class
     */
    public function update(UpdateClassRequest $request, int $classId)
    {
        try {
            // SECURITY: Get class first to authorize with policy
            $class = \App\Models\ClassModel::findOrFail($classId);

            // POLICY: Enforces tenant isolation and role-based access
            $this->authorize('update', $class);

            // SECURITY: Pass user's school_id to ensure tenant isolation
            $class = $this->classService->update($classId, $request->validated(), $request->user()->school_id);

            return response()->json([
                'success' => true,
                'message' => 'Kelas berhasil diperbarui',
                'data' => $class,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Update Class Status
     */
    public function updateStatus(UpdateClassStatusRequest $request, int $classId)
    {
        try {
            // SECURITY: Get class for policy authorization
            $class = \App\Models\ClassModel::findOrFail($classId);
            
            // POLICY: Enforces tenant isolation and role-based access
            $this->authorize('update', $class);

            // SECURITY: Pass user's school_id to ensure tenant isolation
            $this->classService->updateStatus($classId, $request->validated()['is_active'], $request->user()->school_id);

            return response()->json([
                'success' => true,
                'message' => 'Status kelas diperbarui',
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete Class
     */
    public function destroy(Request $request, int $classId)
    {
        try {
            // SECURITY: Get class for policy authorization
            $class = \App\Models\ClassModel::findOrFail($classId);
            
            // POLICY: Enforces tenant isolation and role-based access
            $this->authorize('delete', $class);

            // SECURITY: Use model with policy instead of direct DB access
            $class->delete();

            return response()->json([
                'success' => true,
                'message' => 'Kelas berhasil dihapus',
            ]);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Kelas tidak ditemukan'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
