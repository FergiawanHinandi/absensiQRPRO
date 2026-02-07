<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Permission\StorePermissionRequest;
use App\Http\Requests\Permission\UpdatePermissionStatusRequest;
use App\Models\StudentPermission;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PermissionController - Student Permission Management
 *
 * CLEAN ARCHITECTURE:
 * - FormRequest handles validation
 * - Service layer handles all business logic
 * - Controller only orchestrates request → service → response
 *
 * NO raw DB::table() calls - uses Eloquent models exclusively
 */
class PermissionController extends Controller
{
    public function __construct(
        private PermissionService $permissionService
    ) {}

    /**
     * List Permissions (filtered by user role)
     *
     * GET /api/v1/permissions
     */
    public function index(Request $request): JsonResponse
    {
        $permissions = $this->permissionService->listForUser(
            $request->user(),
            ['status' => $request->input('status')]
        );

        return response()->json([
            'success' => true,
            'data' => $permissions,
        ]);
    }

    /**
     * Store Permission (Student Request OR Teacher Input)
     *
     * POST /api/v1/permissions
     */
    public function store(StorePermissionRequest $request): JsonResponse
    {
        $permission = $this->permissionService->store(
            $request->user(),
            $request->validated(),
            $request->file('attachment')
        );

        return response()->json([
            'success' => true,
            'message' => 'Izin berhasil disimpan.',
            'data' => ['id' => $permission->id],
        ], 201);
    }

    /**
     * Approve/Reject Permission (Teacher Action)
     *
     * PUT/PATCH /api/v1/permissions/{id}/status
     */
    public function updateStatus(UpdatePermissionStatusRequest $request, int $id): JsonResponse
    {
        $permission = StudentPermission::findOrFail($id);
        $validated = $request->validated();

        try {
            if ($validated['status'] === 'approved') {
                $result = $this->permissionService->approve($permission, $request->user());
                $message = 'Izin berhasil disetujui.';
            } else {
                $result = $this->permissionService->reject($permission, $request->user());
                $message = 'Izin ditolak.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $result,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
