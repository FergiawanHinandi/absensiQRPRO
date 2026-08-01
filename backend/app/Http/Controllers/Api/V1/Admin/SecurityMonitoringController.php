<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Models\SuspiciousDevice;
use App\Models\SuspiciousStudent;
use App\Services\SecurityMonitoringService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SecurityMonitoringController extends Controller
{
    protected SecurityMonitoringService $securityService;

    public function __construct(SecurityMonitoringService $securityService)
    {
        $this->securityService = $securityService;
    }

    /**
     * Get paginated security events
     */
    public function events(Request $request)
    {
        $user = Auth::user();
        $schoolId = $user->school_id;
        $perPage = $request->get('per_page', 20);
        $severity = $request->get('severity');
        $eventType = $request->get('event_type');
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $query = SecurityEvent::where('school_id', $schoolId)
            ->with(['user:id,name,email', 'student:id,name'])
            ->orderByDesc('created_at');

        if ($severity) {
            $query->where('severity', $severity);
        }

        if ($eventType) {
            $query->where('event_type', $eventType);
        }

        if ($startDate) {
            $query->where('created_at', '>=', Carbon::parse($startDate)->startOfDay());
        }

        if ($endDate) {
            $query->where('created_at', '<=', Carbon::parse($endDate)->endOfDay());
        }

        $events = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $events,
        ]);
    }

    /**
     * Get security summary/dashboard metrics
     */
    public function summary(Request $request)
    {
        $user = Auth::user();
        $schoolId = $user->school_id;
        $date = $request->get('date', now()->toDateString());

        $metrics = $this->securityService->getDashboardMetrics($schoolId, $date);
        $eventTypes = $this->securityService->getEventTypeBreakdown($schoolId);
        $severityBreakdown = $this->securityService->getSeverityBreakdown($schoolId);

        return response()->json([
            'success' => true,
            'data' => [
                'metrics' => $metrics,
                'event_type_breakdown' => $eventTypes,
                'severity_breakdown' => $severityBreakdown,
                'date' => $date,
            ],
        ]);
    }

    /**
     * Get suspicious students list
     */
    public function suspiciousStudents(Request $request)
    {
        $user = Auth::user();
        $schoolId = $user->school_id;
        $status = $request->get('status', 'flagged');
        $perPage = $request->get('per_page', 20);

        $students = SuspiciousStudent::where('school_id', $schoolId)
            ->when($status, fn($q) => $q->where('status', $status))
            ->with(['student:id,name,username,email'])
            ->orderByDesc('violation_count')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $students,
        ]);
    }

    /**
     * Get suspicious devices list
     */
    public function suspiciousDevices(Request $request)
    {
        $user = Auth::user();
        $schoolId = $user->school_id;
        $perPage = $request->get('per_page', 20);
        $riskLevel = $request->get('risk_level');

        $query = SuspiciousDevice::where('school_id', $schoolId)
            ->orderByDesc('last_seen_at');

        if ($riskLevel) {
            $query->where('risk_level', $riskLevel);
        }

        $devices = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $devices,
        ]);
    }

    /**
     * Get top flagged students ranked by violation count
     */
    public function topFlaggedStudents(Request $request)
    {
        $user = Auth::user();
        $schoolId = $user->school_id;
        $limit = $request->get('limit', 10);

        $students = SuspiciousStudent::where('school_id', $schoolId)
            ->where('status', 'flagged')
            ->with(['student:id,name,username,email,class_id'])
            ->orderByDesc('violation_count')
            ->limit($limit)
            ->get()
            ->map(function ($flag) {
                return [
                    'id' => $flag->id,
                    'student_id' => $flag->student_id,
                    'student_name' => $flag->student->name ?? 'Unknown',
                    'student_username' => $flag->student->username ?? null,
                    'flag_reason' => $flag->flag_reason,
                    'violation_count' => $flag->violation_count,
                    'evidence' => $flag->evidence,
                    'status' => $flag->status,
                    'flagged_at' => $flag->flagged_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $students,
        ]);
    }

    /**
     * Review a suspicious student (mark as reviewed/cleared)
     */
    public function reviewStudent(Request $request, int $id)
    {
        $user = Auth::user();
        $schoolId = $user->school_id;

        $flag = SuspiciousStudent::where('school_id', $schoolId)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'status' => 'required|in:reviewed,cleared,flagged',
            'notes' => 'nullable|string|max:500',
        ]);

        $flag->update([
            'status' => $validated['status'],
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'notes' => $validated['notes'] ?? $flag->notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Status siswa berhasil diperbarui',
            'data' => $flag->fresh(),
        ]);
    }

    /**
     * Toggle device block status
     */
    public function toggleDeviceBlock(Request $request, int $id)
    {
        $user = Auth::user();
        $schoolId = $user->school_id;

        $device = SuspiciousDevice::where('school_id', $schoolId)
            ->where('id', $id)
            ->firstOrFail();

        $device->update([
            'blocked' => !$device->blocked,
        ]);

        return response()->json([
            'success' => true,
            'message' => $device->blocked ? 'Perangkat berhasil diblokir' : 'Blokir perangkat dicabut',
            'data' => [
                'id' => $device->id,
                'device_id' => $device->device_id,
                'blocked' => $device->blocked,
            ],
        ]);
    }
    /**
     * Get security trend data for charts
     */
    public function trend(Request $request)
    {
        $range = $request->get('range', '7d');
        $days = $range === '30d' ? 30 : 7;

        $user = Auth::user();
        $startDate = Carbon::now()->subDays($days)->startOfDay();

        $trendData = DB::table('security_events')
            ->where('school_id', $user->school_id)
            ->where('created_at', '>=', $startDate)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN severity = "critical" THEN 1 ELSE 0 END) as critical'),
                DB::raw('SUM(CASE WHEN severity = "high" THEN 1 ELSE 0 END) as high'),
                DB::raw('SUM(CASE WHEN severity = "medium" THEN 1 ELSE 0 END) as medium'),
                DB::raw('SUM(CASE WHEN severity = "low" THEN 1 ELSE 0 END) as low')
            )
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'total' => (int) $item->total,
                    'critical' => (int) $item->critical,
                    'high' => (int) $item->high,
                    'medium' => (int) $item->medium,
                    'low' => (int) $item->low,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $trendData,
        ]);
    }

    /**
     * Get security events grouped by type
     */
    public function byType(Request $request)
    {
        $range = $request->get('range', '7d');
        $days = $range === '30d' ? 30 : 7;

        $user = Auth::user();
        $startDate = Carbon::now()->subDays($days)->startOfDay();

        $typeData = DB::table('security_events')
            ->where('school_id', $user->school_id)
            ->where('created_at', '>=', $startDate)
            ->select(
                'event_type',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(CASE WHEN severity = "critical" THEN 1 ELSE 0 END) as critical_count')
            )
            ->groupBy('event_type')
            ->orderBy('count', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'event_type' => $item->event_type,
                    'label' => ucwords(str_replace('_', ' ', $item->event_type)),
                    'count' => (int) $item->count,
                    'critical_count' => (int) $item->critical_count,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $typeData,
        ]);
    }

    /**
     * Get security events grouped by severity
     */
    public function bySeverity(Request $request)
    {
        $range = $request->get('range', '7d');
        $days = $range === '30d' ? 30 : 7;

        $user = Auth::user();
        $startDate = Carbon::now()->subDays($days)->startOfDay();

        $severityData = DB::table('security_events')
            ->where('school_id', $user->school_id)
            ->where('created_at', '>=', $startDate)
            ->select(
                'severity',
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('severity')
            ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')")
            ->get()
            ->map(function ($item) {
                return [
                    'severity' => $item->severity,
                    'count' => (int) $item->count,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $severityData,
        ]);
    }

    /**
     * Get recent critical security events
     */
    public function criticalRecent(Request $request)
    {
        $limit = $request->get('limit', 20);
        $user = Auth::user();

        $criticalEvents = DB::table('security_events')
            ->leftJoin('users', 'security_events.user_id', '=', 'users.id')
            ->leftJoin('schools', 'security_events.school_id', '=', 'schools.id')
            ->where('security_events.school_id', $user->school_id)
            ->whereIn('security_events.severity', ['high', 'critical'])
            ->select(
                'security_events.id',
                'security_events.event_type',
                'security_events.severity',
                'security_events.description',
                'security_events.ip_address',
                'security_events.device_id',
                'security_events.is_resolved',
                'security_events.created_at',
                'security_events.metadata',
                'users.name as user_name',
                'users.email as user_email',
                'users.role as user_role',
                'schools.name as school_name',
                'schools.id as school_id'
            )
            ->orderBy('security_events.created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($event) {
                return [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'event_label' => ucwords(str_replace('_', ' ', $event->event_type)),
                    'severity' => $event->severity,
                    'description' => $event->description,
                    'user_name' => $event->user_name,
                    'user_email' => $event->user_email,
                    'user_role' => $event->user_role,
                    'school_name' => $event->school_name,
                    'school_id' => (int) $event->school_id,
                    'ip_address' => $event->ip_address,
                    'device_id' => $event->device_id,
                    'is_resolved' => (bool) $event->is_resolved,
                    'timestamp' => $event->created_at,
                    'time_ago' => Carbon::parse($event->created_at)->diffForHumans(),
                    'details' => $event->metadata ? json_decode($event->metadata, true) : null,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $criticalEvents,
        ]);
    }
}
