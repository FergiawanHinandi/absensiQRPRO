<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\TeacherAttendance;
use App\Models\TeacherAttendanceAnomaly;
use App\Models\TeacherDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherDeviceController extends Controller
{
    /**
     * Get all teacher devices for the school
     *
     * GET /api/v1/admin/teacher-devices
     */
    public function index(Request $request): JsonResponse
    {
        $admin = $request->user();

        $devices = TeacherDevice::where('school_id', $admin->school_id)
            ->with('teacher:id,name,email')
            ->orderBy('is_approved', 'asc') // Pending first
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => [
                'devices' => $devices->map(fn ($d) => [
                    'id' => $d->id,
                    'teacher' => [
                        'id' => $d->teacher->id,
                        'name' => $d->teacher->name,
                        'email' => $d->teacher->email,
                    ],
                    'device_id' => $d->device_id,
                    'device_name' => $d->device_name,
                    'device_model' => $d->device_model,
                    'platform' => $d->platform,
                    'is_approved' => $d->is_approved,
                    'approved_at' => $d->approved_at?->format('Y-m-d H:i'),
                    'last_used_at' => $d->last_used_at?->format('Y-m-d H:i'),
                    'created_at' => $d->created_at->format('Y-m-d H:i'),
                ]),
                'pagination' => [
                    'current_page' => $devices->currentPage(),
                    'last_page' => $devices->lastPage(),
                    'per_page' => $devices->perPage(),
                    'total' => $devices->total(),
                ],
            ],
        ]);
    }

    /**
     * Get pending device approvals
     *
     * GET /api/v1/admin/teacher-devices/pending
     */
    public function pending(Request $request): JsonResponse
    {
        $admin = $request->user();

        $devices = TeacherDevice::where('school_id', $admin->school_id)
            ->where('is_approved', false)
            ->with('teacher:id,name,email')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'pending_count' => $devices->count(),
                'devices' => $devices->map(fn ($d) => [
                    'id' => $d->id,
                    'teacher' => [
                        'id' => $d->teacher->id,
                        'name' => $d->teacher->name,
                        'email' => $d->teacher->email,
                    ],
                    'device_id' => $d->device_id,
                    'device_name' => $d->device_name,
                    'device_model' => $d->device_model,
                    'platform' => $d->platform,
                    'requested_at' => $d->created_at->format('Y-m-d H:i'),
                ]),
            ],
        ]);
    }

    /**
     * Approve a teacher device
     *
     * POST /api/v1/admin/teacher-devices/{id}/approve
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();

        $device = TeacherDevice::where('school_id', $admin->school_id)
            ->where('id', $id)
            ->first();

        if (! $device) {
            return response()->json([
                'status' => 'fail',
                'message' => 'Perangkat tidak ditemukan.',
            ], 404);
        }

        if ($device->is_approved) {
            return response()->json([
                'status' => 'fail',
                'message' => 'Perangkat sudah disetujui sebelumnya.',
            ], 422);
        }

        $device->update([
            'is_approved' => true,
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Perangkat berhasil disetujui.',
            'data' => [
                'device' => [
                    'id' => $device->id,
                    'device_name' => $device->device_name,
                    'is_approved' => true,
                    'approved_at' => $device->approved_at->format('Y-m-d H:i'),
                ],
            ],
        ]);
    }

    /**
     * Revoke a teacher device approval
     *
     * POST /api/v1/admin/teacher-devices/{id}/revoke
     */
    public function revoke(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();

        $device = TeacherDevice::where('school_id', $admin->school_id)
            ->where('id', $id)
            ->first();

        if (! $device) {
            return response()->json([
                'status' => 'fail',
                'message' => 'Perangkat tidak ditemukan.',
            ], 404);
        }

        $device->update([
            'is_approved' => false,
            'approved_by' => null,
            'approved_at' => null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Persetujuan perangkat telah dicabut.',
        ]);
    }

    /**
     * Delete a teacher device
     *
     * DELETE /api/v1/admin/teacher-devices/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();

        $device = TeacherDevice::where('school_id', $admin->school_id)
            ->where('id', $id)
            ->first();

        if (! $device) {
            return response()->json([
                'status' => 'fail',
                'message' => 'Perangkat tidak ditemukan.',
            ], 404);
        }

        $device->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Perangkat berhasil dihapus.',
        ]);
    }

    /**
     * Get teacher attendance anomalies for review
     *
     * GET /api/v1/admin/teacher-attendance/anomalies
     */
    public function anomalies(Request $request): JsonResponse
    {
        $admin = $request->user();

        $request->validate([
            'reviewed' => 'nullable|boolean',
            'severity' => 'nullable|in:low,medium,high,critical',
        ]);

        $query = TeacherAttendanceAnomaly::where('school_id', $admin->school_id)
            ->with('teacher:id,name,email');

        if ($request->has('reviewed')) {
            $query->where('is_reviewed', $request->boolean('reviewed'));
        }

        if ($request->has('severity')) {
            $query->where('severity', $request->severity);
        }

        $anomalies = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => [
                'anomalies' => $anomalies->map(fn ($a) => [
                    'id' => $a->id,
                    'teacher' => [
                        'id' => $a->teacher->id,
                        'name' => $a->teacher->name,
                    ],
                    'type' => $a->anomaly_type,
                    'severity' => $a->severity,
                    'details' => $a->details,
                    'latitude' => $a->latitude,
                    'longitude' => $a->longitude,
                    'device_id' => $a->device_id,
                    'is_reviewed' => $a->is_reviewed,
                    'created_at' => $a->created_at->format('Y-m-d H:i'),
                ]),
                'pagination' => [
                    'current_page' => $anomalies->currentPage(),
                    'last_page' => $anomalies->lastPage(),
                    'per_page' => $anomalies->perPage(),
                    'total' => $anomalies->total(),
                ],
            ],
        ]);
    }

    /**
     * Mark anomaly as reviewed
     *
     * POST /api/v1/admin/teacher-attendance/anomalies/{id}/review
     */
    public function reviewAnomaly(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();

        $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        $anomaly = TeacherAttendanceAnomaly::where('school_id', $admin->school_id)
            ->where('id', $id)
            ->first();

        if (! $anomaly) {
            return response()->json([
                'status' => 'fail',
                'message' => 'Anomali tidak ditemukan.',
            ], 404);
        }

        $anomaly->markAsReviewed($admin->id, $request->notes);

        return response()->json([
            'status' => 'success',
            'message' => 'Anomali telah ditandai sebagai ditinjau.',
        ]);
    }

    /**
     * Get all teacher attendances for the school
     *
     * GET /api/v1/admin/teacher-attendance
     */
    public function attendances(Request $request): JsonResponse
    {
        $admin = $request->user();

        $request->validate([
            'date' => 'nullable|date',
            'teacher_id' => 'nullable|exists:users,id',
            'status' => 'nullable|in:present,late,absent,sick,permit,excused',
        ]);

        $query = TeacherAttendance::where('school_id', $admin->school_id)
            ->with('teacher:id,name,email');

        if ($request->has('date')) {
            $query->whereDate('attendance_date', $request->date);
        }

        if ($request->has('teacher_id')) {
            $query->where('teacher_id', $request->teacher_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $attendances = $query->orderBy('attendance_date', 'desc')
            ->orderBy('check_in_time', 'desc')
            ->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => [
                'attendances' => $attendances->map(fn ($a) => [
                    'id' => $a->id,
                    'teacher' => [
                        'id' => $a->teacher->id,
                        'name' => $a->teacher->name,
                        'email' => $a->teacher->email,
                    ],
                    'date' => $a->attendance_date->format('Y-m-d'),
                    'status' => $a->status,
                    'check_in_time' => $a->check_in_time?->format('H:i:s'),
                    'check_out_time' => $a->check_out_time?->format('H:i:s'),
                    'distance_in' => $a->distance_in ? round($a->distance_in, 2).'m' : null,
                    'is_manual' => $a->is_manual,
                ]),
                'pagination' => [
                    'current_page' => $attendances->currentPage(),
                    'last_page' => $attendances->lastPage(),
                    'per_page' => $attendances->perPage(),
                    'total' => $attendances->total(),
                ],
            ],
        ]);
    }
}
