<?php

namespace App\Http\Controllers\Api\V1\Parent;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ParentController extends Controller
{
    /**
     * Get children list with latest status
     */
    public function myChildren(Request $request)
    {
        $user = $request->user();

        // Ensure user is a parent
        if ($user->role_type !== 'parent') {
            return response()->json(['message' => 'Unauthorized role'], 403);
        }

        $children = $user->children()
            ->with(['classStudent.class_model'])
            ->get()
            ->map(function ($child) {
                // Get latest attendance for today
                $today = Carbon::today()->toDateString();
                $attendance = Attendance::with(['student', 'schedule.class', 'schedule.subject', 'schedule.teacher'])
                    ->where('student_id', $child->id)
                    ->whereDate('date', $today)
                    ->latest()
                    ->first();

                $className = $child->classStudent && $child->classStudent->class_model
                    ? $child->classStudent->class_model->name
                    : 'Belum Masuk Kelas';

                return [
                    'id' => $child->id,
                    'name' => $child->name,
                    'class_name' => $className,
                    'latest_attendance' => $attendance ? [
                        'status' => $attendance->status,
                        'time' => $attendance->check_in,
                    ] : null,
                    'relationship' => $child->pivot->relationship,
                ];
            });

        return response()->json([
            'data' => $children,
        ]);
    }
}
