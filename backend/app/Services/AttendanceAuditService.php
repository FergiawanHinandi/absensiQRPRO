<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AttendanceAuditService
 * 
 * Service for querying and analyzing attendance state machine audit logs.
 * Provides comprehensive audit trail reporting and analysis.
 */
class AttendanceAuditService
{
    /**
     * Get complete audit trail for an attendance record
     */
    public function getAuditTrail(int $attendanceId): Collection
    {
        return AttendanceLog::where('attendance_id', $attendanceId)
            ->where('action', 'state_transition')
            ->with(['user', 'performer:id,name,email'])
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'timestamp' => $log->created_at,
                    'from_state' => $log->from_state,
                    'to_state' => $log->to_state,
                    'performed_by' => $log->performer?->name ?? 'System',
                    'performer_email' => $log->performer?->email,
                    'reason' => $log->reason,
                    'changes' => $log->changes,
                    'ip_address' => $log->ip_address,
                    'device_info' => $log->device_info,
                    'notes' => $log->notes,
                ];
            });
    }

    /**
     * Get state transition statistics for a school
     */
    public function getTransitionStatistics(int $schoolId, ?string $startDate = null, ?string $endDate = null): array
    {
        $query = AttendanceLog::where('school_id', $schoolId)
            ->where('action', 'state_transition')
            ->whereNotNull('from_state')
            ->whereNotNull('to_state');

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }

        $transitions = $query->select(
            'from_state',
            'to_state',
            DB::raw('COUNT(*) as count')
        )
            ->groupBy('from_state', 'to_state')
            ->get();

        return [
            'total_transitions' => $transitions->sum('count'),
            'transitions_by_type' => $transitions->map(function ($item) {
                return [
                    'from' => $item->from_state,
                    'to' => $item->to_state,
                    'count' => $item->count,
                ];
            })->toArray(),
            'most_common_transition' => $transitions->sortByDesc('count')->first(),
        ];
    }

    /**
     * Get user activity report (who performed most state changes)
     */
    public function getUserActivityReport(int $schoolId, ?string $startDate = null, ?string $endDate = null): Collection
    {
        $query = AttendanceLog::where('school_id', $schoolId)
            ->where('action', 'state_transition')
            ->whereNotNull('performed_by');

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }

        return $query->select(
            'performed_by',
            DB::raw('COUNT(*) as total_actions'),
            DB::raw('COUNT(DISTINCT attendance_id) as unique_attendances'),
            DB::raw('MIN(created_at) as first_action'),
            DB::raw('MAX(created_at) as last_action')
        )
            ->with('performer:id,name,email,role')
            ->groupBy('performed_by')
            ->orderByDesc('total_actions')
            ->get()
            ->map(function ($item) {
                return [
                    'user_id' => $item->performed_by,
                    'user_name' => $item->performer?->name,
                    'user_email' => $item->performer?->email,
                    'user_role' => $item->performer?->role,
                    'total_actions' => $item->total_actions,
                    'unique_attendances' => $item->unique_attendances,
                    'first_action' => $item->first_action,
                    'last_action' => $item->last_action,
                ];
            });
    }

    /**
     * Detect suspicious activity patterns
     */
    public function detectSuspiciousActivity(int $schoolId, ?string $startDate = null): array
    {
        $query = AttendanceLog::where('school_id', $schoolId)
            ->where('action', 'state_transition');

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }

        $suspicious = [];

        // Check for rapid state changes (multiple transitions in short time)
        $rapidChanges = $query->clone()
            ->select('attendance_id', DB::raw('COUNT(*) as transition_count'))
            ->groupBy('attendance_id')
            ->having('transition_count', '>', 5)
            ->get();

        if ($rapidChanges->isNotEmpty()) {
            $suspicious['rapid_state_changes'] = [
                'description' => 'Attendance records with more than 5 state transitions',
                'count' => $rapidChanges->count(),
                'attendance_ids' => $rapidChanges->pluck('attendance_id')->toArray(),
            ];
        }

        // Check for unusual IP addresses (same user from multiple IPs)
        $multipleIPs = $query->clone()
            ->whereNotNull('performed_by')
            ->select('performed_by', DB::raw('COUNT(DISTINCT ip_address) as ip_count'))
            ->groupBy('performed_by')
            ->having('ip_count', '>', 3)
            ->get();

        if ($multipleIPs->isNotEmpty()) {
            $suspicious['multiple_ip_addresses'] = [
                'description' => 'Users performing actions from more than 3 different IP addresses',
                'count' => $multipleIPs->count(),
                'user_ids' => $multipleIPs->pluck('performed_by')->toArray(),
            ];
        }

        // Check for after-hours modifications (outside 6 AM - 10 PM)
        $afterHours = $query->clone()
            ->whereRaw('HOUR(created_at) < 6 OR HOUR(created_at) > 22')
            ->count();

        if ($afterHours > 0) {
            $suspicious['after_hours_modifications'] = [
                'description' => 'State transitions performed outside normal hours (6 AM - 10 PM)',
                'count' => $afterHours,
            ];
        }

        return $suspicious;
    }

    /**
     * Get approval workflow metrics
     */
    public function getApprovalMetrics(int $schoolId, ?string $startDate = null, ?string $endDate = null): array
    {
        $query = AttendanceLog::where('school_id', $schoolId)
            ->where('action', 'state_transition');

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }

        $approvals = $query->clone()->where('to_state', 'approved')->count();
        $rejections = $query->clone()->where('to_state', 'rejected')->count();
        $pending = $query->clone()->where('to_state', 'pending_approval')->count();

        $total = $approvals + $rejections;
        $approvalRate = $total > 0 ? round(($approvals / $total) * 100, 2) : 0;

        return [
            'total_approvals' => $approvals,
            'total_rejections' => $rejections,
            'pending_approvals' => $pending,
            'approval_rate' => $approvalRate,
            'rejection_rate' => $total > 0 ? round(($rejections / $total) * 100, 2) : 0,
        ];
    }

    /**
     * Export audit trail to CSV format
     */
    public function exportAuditTrail(int $schoolId, ?string $startDate = null, ?string $endDate = null): string
    {
        $query = AttendanceLog::where('school_id', $schoolId)
            ->where('action', 'state_transition')
            ->with(['attendance.student:id,name', 'performer:id,name']);

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }

        $logs = $query->orderBy('created_at', 'desc')->get();

        $csv = "Timestamp,Attendance ID,Student Name,From State,To State,Performed By,Reason,IP Address\n";

        foreach ($logs as $log) {
            $csv .= sprintf(
                "%s,%d,%s,%s,%s,%s,%s,%s\n",
                $log->created_at,
                $log->attendance_id,
                $log->attendance?->student?->name ?? 'N/A',
                $log->from_state,
                $log->to_state,
                $log->performer?->name ?? 'System',
                str_replace(',', ';', $log->reason ?? ''),
                $log->ip_address
            );
        }

        return $csv;
    }
}
