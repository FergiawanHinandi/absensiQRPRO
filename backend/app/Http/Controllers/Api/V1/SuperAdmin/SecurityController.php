<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SecurityController extends Controller
{
    /**
     * Get Roles & Permissions
     */
    public function roles(Request $request)
    {
        $roles = Role::with('permissions')->get();
        // Get all permissions grouped by module (assuming naming convention 'action module')
        $permissions = Permission::all()->groupBy(function ($item) {
            $parts = explode(' ', $item->name);

            return end($parts); // Group by last word
        });

        return response()->json([
            'success' => true,
            'data' => [
                'roles' => $roles,
                'permissions_grouped' => $permissions,
            ],
        ]);
    }

    /**
     * Get Audit Logs
     */
    public function auditLogs(Request $request)
    {
        $query = \App\Models\AuditLog::with('user');

        if ($request->has('search')) {
            $search = $request->search;
            $query->where('action', 'ILIKE', "%{$search}%")
                ->orWhere('description', 'ILIKE', "%{$search}%")
                ->orWhereHas('user', function ($q) use ($search) {
                    $q->where('name', 'ILIKE', "%{$search}%");
                });
        }

        $logsData = $query->orderBy('created_at', 'desc')->paginate(20);

        // Transform data to match frontend expectation
        $logs = collect($logsData->items())->map(function ($log) {
            // Simple module detection from action string
            $parts = explode('_', $log->action);
            $module = count($parts) > 1 ? $parts[1] : 'system'; // e.g. create_user -> user

            return [
                'id' => $log->id,
                'user' => $log->user->name ?? 'System/Deleted User',
                'action' => $log->action,
                'module' => $module,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'status' => 'success', // Default success for now
                'created_at' => $log->created_at->toDateTimeString(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }

    /**
     * Get Rate Limit Stats
     */
    public function rateLimitStats(Request $request)
    {
        // Mocking Rate Limit Data
        $metrics = [
            'total_requests' => 15420,
            'blocked_requests' => 142,
            'average_latency' => '45ms',
            'active_ips' => 850,
        ];

        // Hourly traffic mock
        $traffic = [];
        for ($i = 0; $i < 24; $i++) {
            $traffic[] = [
                'hour' => sprintf('%02d:00', $i),
                'requests' => rand(100, 2000),
                'blocked' => rand(0, 50),
            ];
        }

        // Top IPs
        $topIps = [
            ['ip' => '192.168.1.10', 'requests' => 5400, 'status' => 'normal'],
            ['ip' => '10.0.0.5', 'requests' => 3200, 'status' => 'normal'],
            ['ip' => '172.16.0.1', 'requests' => 1500, 'status' => 'warning'],
            ['ip' => '45.32.11.22', 'requests' => 850, 'status' => 'blocked'],
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'metrics' => $metrics,
                'traffic' => $traffic,
                'top_ips' => $topIps,
            ],
        ]);
    }
}
