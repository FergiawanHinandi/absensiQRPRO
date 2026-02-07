<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SecurityAlert;
use Illuminate\Http\Request;

class SecurityAlertController extends Controller
{
    /**
     * Get list of security alerts
     */
    public function index(Request $request)
    {
        $alerts = SecurityAlert::orderBy('created_at', 'desc')
            ->when($request->resolved, function ($q) {
                return $q->where('is_resolved', FILTER_VALIDATE_BOOLEAN);
            })
            ->when($request->severity, function ($q) use ($request) {
                return $q->where('severity', $request->severity);
            })
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $alerts,
        ]);
    }

    /**
     * Resolve an alert
     */
    public function resolve($id, Request $request)
    {
        $alert = SecurityAlert::findOrFail($id);

        $alert->update([
            'is_resolved' => true,
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Alert marked as resolved.',
        ]);
    }
}
