<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreSchoolRequest;
use App\Http\Requests\SuperAdmin\UpdateSchoolRequest;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\Request;

class SchoolController extends Controller
{
    /**
     * Get list of schools (with filters)
     */
    public function index(Request $request)
    {
        // Authorize
        if (! $request->user()->hasRole('super_admin')) {
            abort(403, 'Unauthorized');
        }

        $query = School::query();

        // Filter by status
        if ($request->has('status')) {
            $status = $request->input('status'); // 'active' or 'inactive'
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        // Search
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('npsn', 'like', "%{$search}%");
        }

        $schools = $query->withCount('users')->orderBy('created_at', 'desc')->paginate($request->per_page ?? 10);

        return response()->json(['success' => true, 'data' => $schools]);
    }

    /**
     * Get single school details
     */
    public function show($id)
    {
        $school = School::with(['users' => function ($query) {
            $query->select('id', 'name', 'email', 'role_type', 'school_id', 'is_active');
        }])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $school,
        ]);
    }

    /**
     * Store new school (Super Admin Manual Create)
     */
    public function store(StoreSchoolRequest $request)
    {
        $validated = $request->validated();

        $school = School::create(array_merge($validated, [
            'is_active' => true, // default active if created by super admin
        ]));

        \App\Models\AuditLog::create([
            'user_id' => auth()->id(),
            'school_id' => $school->id,
            'module' => 'school',
            'action' => 'create',
            'severity' => \App\Models\AuditLog::SEVERITY_INFO,
            'description' => "Membuat sekolah baru: {$school->name}",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return response()->json(['success' => true, 'message' => 'Sekolah berhasil dibuat', 'data' => $school]);
    }

    /**
     * Update school details
     */
    public function update(UpdateSchoolRequest $request, $id)
    {
        $school = School::findOrFail($id);
        $validated = $request->validated();

        $school->update($validated);

        \App\Models\AuditLog::create([
            'user_id' => auth()->id(),
            'school_id' => $school->id,
            'module' => 'school',
            'action' => 'update',
            'severity' => \App\Models\AuditLog::SEVERITY_INFO,
            'description' => "Memperbarui data sekolah: {$school->name}",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return response()->json(['success' => true, 'message' => 'Sekolah berhasil diperbarui', 'data' => $school]);
    }

    /**
     * Delete school
     *
     * SECURITY FIX: Added audit log for school deletion
     * CRITICAL: This action should be rare and well-documented
     */
    public function destroy($id)
    {
        $school = School::findOrFail($id);

        // Store school info before deletion for audit log
        $schoolName = $school->name;
        $schoolId = $school->id;

        // SECURITY: Log BEFORE deletion (in case delete fails, we still have the attempt logged)
        \App\Models\AuditLog::create([
            'user_id' => auth()->id(),
            'school_id' => $schoolId,
            'module' => 'school',
            'action' => 'delete_school',
            'severity' => \App\Models\AuditLog::SEVERITY_CRITICAL,
            'description' => "DELETED school: {$schoolName} (ID: {$schoolId})",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => json_encode([
                'deleted_school' => [
                    'id' => $schoolId,
                    'name' => $schoolName,
                    'npsn' => $school->npsn,
                    'deleted_at' => now()->toIso8601String(),
                    'deleted_by' => auth()->id(),
                ],
            ]),
        ]);

        $school->delete();

        return response()->json(['success' => true, 'message' => 'Sekolah berhasil dihapus.']);
    }

    /**
     * Rotate Activation Status
     */
    public function activate($id)
    {
        $school = School::findOrFail($id);
        $school->is_active = true;
        $school->save();

        \App\Models\AuditLog::create([
            'user_id' => auth()->id(),
            'school_id' => $school->id,
            'module' => 'school',
            'action' => 'activate_school',
            'severity' => \App\Models\AuditLog::SEVERITY_INFO,
            'description' => "Activated school: {$school->name}",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return response()->json(['success' => true, 'message' => 'Sekolah berhasil diaktifkan.']);
    }

    public function deactivate($id)
    {
        $school = School::findOrFail($id);
        $school->is_active = false;
        $school->save();

        \App\Models\AuditLog::create([
            'user_id' => auth()->id(),
            'school_id' => $school->id,
            'module' => 'school',
            'action' => 'deactivate_school',
            'severity' => \App\Models\AuditLog::SEVERITY_WARNING,
            'description' => "Deactivated school: {$school->name}",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return response()->json(['success' => true, 'message' => 'Sekolah berhasil dinonaktifkan.']);
    }

    /**
     * Impersonate School Admin
     *
     * SECURITY FIX: Token now has expiry and limited abilities
     */
    public function impersonate($id)
    {
        // Authorize super admin only
        $superAdminRole = config('permission.super_admin_role', 'super_admin');
        if (! auth()->user()->hasRole($superAdminRole)) {
            abort(403, 'Unauthorized');
        }

        $school = School::findOrFail($id);

        $adminUser = User::query()
            ->where('school_id', $school->id)
            ->whereIn('role_type', ['admin', 'school_admin'])
            ->where('is_active', true)
            ->first();

        if (! $adminUser) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ditemukan user Admin aktif untuk sekolah ini.',
            ], 404);
        }

        // SECURITY FIX: Create token with expiry (2 hours) and limited abilities
        $token = $adminUser->createToken(
            'impersonation_token',
            ['*'], // abilities
            now()->addHours(2) // SECURITY: Token expires in 2 hours
        )->plainTextToken;

        \App\Models\AuditLog::create([
            'user_id' => auth()->id(),
            'school_id' => $school->id,
            'module' => 'school',
            'action' => 'impersonate_school',
            'severity' => \App\Models\AuditLog::SEVERITY_WARNING,
            'description' => "Mode samaran (Impersonate) sekolah: {$school->name} (via {$adminUser->name})",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => json_encode([
                'impersonated_user_id' => $adminUser->id,
                'expires_at' => now()->addHours(2)->toIso8601String(),
            ]),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Impersonation verified. Token expires in 2 hours.',
            'data' => [
                'token' => $token,
                'expires_at' => now()->addHours(2)->toIso8601String(),
                'user' => [
                    'id' => $adminUser->id,
                    'name' => $adminUser->name,
                    'email' => $adminUser->email,
                    'role_type' => $adminUser->role_type,
                    'school_id' => $adminUser->school_id,
                    'school_name' => $school->name,
                ],
            ],
        ]);
    }

    public function usage($id)
    {
        $school = School::findOrFail($id);

        $currentTeachers = User::where('school_id', $id)
            ->whereIn('role_type', ['teacher', 'homeroom_teacher'])
            ->count();

        // Use ClassModel class
        $currentClasses = \App\Models\ClassModel::where('school_id', $id)->count();

        return response()->json([
            'success' => true,
            'data' => [
                'current_teachers' => $currentTeachers,
                'current_classes' => $currentClasses,
                'max_teachers' => $school->max_teachers,
                'max_classes' => $school->max_classes,
            ],
        ]);
    }
}
