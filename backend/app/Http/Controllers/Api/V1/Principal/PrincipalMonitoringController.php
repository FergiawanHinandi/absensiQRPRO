<?php

namespace App\Http\Controllers\Api\V1\Principal;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\StudentPermission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * PrincipalMonitoringController
 *
 * BE-09 FIX: Controller ini dibuat untuk melengkapi backend endpoint
 * yang dibutuhkan oleh halaman PrincipalMonitoring dan PrincipalApprovals
 * di frontend.
 */
class PrincipalMonitoringController extends Controller
{
    /**
     * Get monitoring overview — data realtime untuk kepala sekolah
     */
    public function index(Request $request): JsonResponse
    {
        $principal = Auth::user();
        $schoolId = $principal->school_id;
        $today = Carbon::today()->toDateString();

        // Absensi hari ini ringkasan
        $todayStats = Attendance::where('school_id', $schoolId)
            ->whereDate('attendance_date', $today)
            ->selectRaw("
                COUNT(DISTINCT student_id) as total_recorded,
                COUNT(DISTINCT CASE WHEN status = 'present' THEN student_id END) as present,
                COUNT(DISTINCT CASE WHEN status = 'late' THEN student_id END) as late,
                COUNT(DISTINCT CASE WHEN status = 'absent' THEN student_id END) as absent,
                COUNT(DISTINCT CASE WHEN status IN ('sick', 'permit') THEN student_id END) as excused
            ")
            ->first();

        $totalStudents = User::where('school_id', $schoolId)
            ->where('role_type', 'student')
            ->where('is_active', true)
            ->count();

        $notRecorded = $totalStudents - ($todayStats->total_recorded ?? 0);

        return response()->json([
            'success' => true,
            'data' => [
                'today' => [
                    'total_students' => $totalStudents,
                    'total_recorded' => $todayStats->total_recorded ?? 0,
                    'present' => $todayStats->present ?? 0,
                    'late' => $todayStats->late ?? 0,
                    'absent' => $todayStats->absent ?? 0,
                    'excused' => $todayStats->excused ?? 0,
                    'not_recorded' => max(0, $notRecorded),
                    'attendance_rate' => $totalStudents > 0
                        ? round((($todayStats->present ?? 0) / $totalStudents) * 100, 1)
                        : 0,
                ],
                'date' => $today,
                'school_id' => $schoolId,
            ],
        ]);
    }

    /**
     * Get realtime attendance data
     */
    public function realtime(Request $request): JsonResponse
    {
        $principal = Auth::user();
        $schoolId = $principal->school_id;
        $today = Carbon::today()->toDateString();

        // Tren per jam hari ini
        $hourlyTrend = Attendance::where('school_id', $schoolId)
            ->whereDate('attendance_date', $today)
            ->whereNotNull('check_in_time')
            ->selectRaw("EXTRACT(HOUR FROM check_in_time) as hour, COUNT(*) as count")
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->map(fn ($item) => [
                'hour' => (int) $item->hour,
                'hour_label' => sprintf('%02d:00', (int) $item->hour),
                'count' => $item->count,
            ]);

        // Absensi terbaru (10 terakhir)
        $recentAttendances = Attendance::where('school_id', $schoolId)
            ->whereDate('attendance_date', $today)
            ->with(['student:id,name'])
            ->select('id', 'student_id', 'status', 'check_in_time', 'attendance_date')
            ->orderByDesc('check_in_time')
            ->limit(10)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'student_name' => $a->student?->name ?? 'Unknown',
                'status' => $a->status,
                'check_in_time' => $a->check_in_time,
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'hourly_trend' => $hourlyTrend,
                'recent_attendances' => $recentAttendances,
                'last_updated' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Get monitoring alerts — siswa dengan absensi mengkhawatirkan
     */
    public function alerts(Request $request): JsonResponse
    {
        $principal = Auth::user();
        $schoolId = $principal->school_id;
        $threshold = $request->input('threshold', 75); // Persentase kehadiran minimum

        $thirtyDaysAgo = Carbon::now()->subDays(30)->toDateString();

        $students = User::where('school_id', $schoolId)
            ->where('role_type', 'student')
            ->where('is_active', true)
            ->select('id', 'name', 'school_id')
            ->with([
                'classStudents:id,student_id,class_id,status',
                'classStudents.class:id,name',
            ])
            ->withCount([
                'attendances as total_days' => fn ($q) => $q->where('attendance_date', '>=', $thirtyDaysAgo),
                'attendances as present_days' => fn ($q) => $q
                    ->where('attendance_date', '>=', $thirtyDaysAgo)
                    ->whereIn('status', ['present', 'late']),
            ])
            ->get()
            ->filter(fn ($s) => $s->total_days > 0 && ($s->present_days / $s->total_days * 100) < $threshold)
            ->map(fn ($s) => [
                'student_id' => $s->id,
                'student_name' => $s->name,
                'class_name' => $s->classStudents->first()?->class?->name ?? 'N/A',
                'attendance_rate' => round(($s->present_days / $s->total_days) * 100, 1),
                'present_days' => $s->present_days,
                'total_days' => $s->total_days,
            ])
            ->sortBy('attendance_rate')
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'alerts' => $students,
                'threshold' => $threshold,
                'total_alerts' => $students->count(),
                'period_days' => 30,
            ],
        ]);
    }

    /**
     * Get pending approvals — izin/dispensasi yang belum disetujui
     *
     * A3-C1 FIX: Ganti DB::table('permission_requests') — tabel tidak ada!
     * Gunakan model StudentPermission (tabel: student_permissions)
     */
    public function pendingApprovals(Request $request): JsonResponse
    {
        $principal = Auth::user();
        $schoolId = $principal->school_id;

        // StudentPermission sudah memiliki BelongsToSchool yang auto-filter per sekolah
        // Tidak perlu filter school_id manual karena sudah ada global scope
        $pendingPermissions = StudentPermission::with([
                'student:id,name',
                'class:id,name',
                'approver:id,name',
            ])
            ->pending()  // scope: where status = 'pending'
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $pendingPermissions,
        ]);
    }

    /**
     * Approve a permission request
     *
     * A3-C1 FIX: Ganti DB::table('permission_requests') dengan StudentPermission model
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $principal = Auth::user();

        // BelongsToSchool scope otomatis menjamin isolasi school
        $permission = StudentPermission::findOrFail($id);

        if ($permission->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Permohonan tidak ditemukan atau sudah diproses.',
            ], 404);
        }

        $permission->update([
            'status' => 'approved',
            'approved_by' => $principal->id,
            'approved_at' => now(),
            'description' => $request->input('notes'),  // 'notes' di controller → 'description' di DB
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Permohonan berhasil disetujui.',
        ]);
    }

    /**
     * Reject a permission request
     *
     * A3-C1 FIX: Ganti DB::table('permission_requests') dengan StudentPermission model
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|min:10',
        ]);

        $principal = Auth::user();

        // BelongsToSchool scope otomatis menjamin isolasi school
        $permission = StudentPermission::findOrFail($id);

        if ($permission->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Permohonan tidak ditemukan atau sudah diproses.',
            ], 404);
        }

        $permission->update([
            'status' => 'rejected',
            'approved_by' => $principal->id,
            'approved_at' => now(),
            'description' => $request->input('reason'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Permohonan berhasil ditolak.',
        ]);
    }
}
