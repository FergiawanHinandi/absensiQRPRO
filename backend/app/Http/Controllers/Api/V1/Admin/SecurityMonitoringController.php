<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SecurityMonitoringController extends Controller
{
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
