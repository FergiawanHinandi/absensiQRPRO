<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Models\DailyAttendanceSummary;
use App\Models\School;
use App\Helpers\Timezone;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class OptimizedDashboardController extends Controller
{
    /**
     * Get Daily Attendance Summary
     * 
     * HIGH SCALE: Uses dedicated summary table, avoided COUNT(*) entirely.
     * Complexity: O(1) by PK lookup.
     */
    public function getSummary(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->school_id) {
            return response()->json(['error' => 'No school context'], 400); 
        }

        $school = School::find($user->school_id); // Cached typically
        $today = Timezone::today($school); // "YYYY-MM-DD"
        
        // Instant Lookup via Composite Index (school_id, date)
        $summary = DailyAttendanceSummary::where('school_id', $user->school_id)
            ->where('date', $today)
            ->first();

        if (!$summary) {
            // No data yet today - return pure zeros, don't fallback to expensive query
            // Fast failure is better than slow success
            return response()->json([
                'date' => $today,
                'total_present' => 0,
                'total_late' => 0,
                'total_absent' => 0,
                'total_permission' => 0,
                'total_sick' => 0,
                'attendance_rate' => 0,
            ]);
        }

        // Calculate Rate (Optional: might query total students efficiently using a cached count)
        // Assume total students is passed or fetched efficiently separately 
        // (For now, just return raw counts as requested)

        return response()->json([
            'date' => $today,
            'total_present' => $summary->total_present,
            'total_late' => $summary->total_late,
            'total_absent' => $summary->total_absent,
            'total_permission' => $summary->total_permission,
            'total_sick' => $summary->total_sick,
            // Simple rate calculation if total students known, else client-side
        ]);
    }
}
