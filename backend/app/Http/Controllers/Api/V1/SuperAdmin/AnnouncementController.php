<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    /**
     * List all announcements with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Announcement::query()
            ->with('creator:id,name')
            ->orderByDesc('created_at');

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->has('target_role')) {
            $query->where('target_role', $request->target_role);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                    ->orWhere('content', 'ilike', "%{$search}%");
            });
        }

        $announcements = $query->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $announcements,
        ]);
    }

    /**
     * Show a single announcement.
     */
    public function show(int $id): JsonResponse
    {
        $announcement = Announcement::with('creator:id,name')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $announcement,
        ]);
    }

    /**
     * Create a new announcement.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'type' => 'sometimes|in:info,warning,critical,success',
            'target_role' => 'sometimes|in:all,admin,school_admin,teacher,student',
            'target_type' => 'sometimes|in:global,school,user',
            'target_ids' => 'nullable|array',
            'target_ids.*' => 'integer',
            'is_active' => 'sometimes|boolean',
            'expires_at' => 'nullable|date|after:now',
        ]);

        // Map legacy priority field if provided
        if ($request->has('priority') && ! $request->has('type')) {
            $priorityMap = ['normal' => 'info', 'high' => 'warning', 'critical' => 'critical'];
            $validated['type'] = $priorityMap[$request->priority] ?? 'info';
        }

        // Handle legacy target_schools field
        if ($request->has('target_schools') && ! $request->has('target_type')) {
            $validated['target_type'] = $request->target_schools ? 'school' : 'global';
            $validated['target_ids'] = $request->target_schools;
        }

        $announcement = Announcement::create(array_merge($validated, [
            'type' => $validated['type'] ?? 'info',
            'target_role' => $validated['target_role'] ?? 'all',
            'target_type' => $validated['target_type'] ?? 'global',
            'created_by' => $request->user()->id,
            'is_active' => $validated['is_active'] ?? true,
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Announcement created successfully.',
            'data' => $announcement->load('creator:id,name'),
        ], 201);
    }

    /**
     * Update an existing announcement.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $announcement = Announcement::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'type' => 'sometimes|in:info,warning,critical,success',
            'target_role' => 'sometimes|in:all,admin,school_admin,teacher,student',
            'target_type' => 'sometimes|in:global,school,user',
            'target_ids' => 'nullable|array',
            'target_ids.*' => 'integer',
            'is_active' => 'sometimes|boolean',
            'expires_at' => 'nullable|date',
        ]);

        $announcement->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Announcement updated successfully.',
            'data' => $announcement->fresh()->load('creator:id,name'),
        ]);
    }

    /**
     * Delete an announcement.
     */
    public function destroy(int $id): JsonResponse
    {
        $announcement = Announcement::findOrFail($id);
        $announcement->delete();

        return response()->json([
            'success' => true,
            'message' => 'Announcement deleted successfully.',
        ]);
    }

    /**
     * Get active announcements for authenticated user (broadcasts endpoint)
     */
    public function getActive(Request $request): JsonResponse
    {
        $user = $request->user();
        $roleType = $user->role_type ?? 'all';

        $query = Announcement::active()
            ->forRole($roleType)
            ->with('creator:id,name')
            ->orderByDesc('created_at');

        // Scope to user's school if applicable
        if ($user->school_id) {
            $query->forSchool($user->school_id);
        } else {
            $query->global();
        }

        $announcements = $query->limit($request->input('limit', 10))->get();

        return response()->json([
            'success' => true,
            'data' => $announcements,
        ]);
    }

    /**
     * Toggle announcement active status.
     */
    public function toggleActive(int $id): JsonResponse
    {
        $announcement = Announcement::findOrFail($id);
        $announcement->update(['is_active' => ! $announcement->is_active]);

        return response()->json([
            'success' => true,
            'message' => $announcement->is_active ? 'Announcement activated.' : 'Announcement deactivated.',
            'data' => ['is_active' => $announcement->is_active],
        ]);
    }
}
