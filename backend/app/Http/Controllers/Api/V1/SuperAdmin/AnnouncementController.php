<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnnouncementController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'target_schools' => 'nullable|array', // If null, global
            'priority' => 'in:normal,high,critical'
        ]);

        // Create announcement
        $priorityMap = [
            'normal' => 'info',
            'high' => 'warning',
            'critical' => 'critical'
        ];
        
        $announcementId = DB::table('announcements')->insertGetId([
            'title' => $request->title,
            'content' => $request->content,
            'type' => $priorityMap[$request->priority ?? 'normal'] ?? 'info',
            'target_role' => 'all', // Default to all roles for now
            'target_type' => $request->target_schools ? 'school' : 'global',
            'target_ids' => $request->target_schools ? json_encode($request->target_schools) : null,
            'created_by' => $request->user()->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Announcement broadcasted successfully.',
            'announcement_id' => $announcementId
        ]);
    }
}
