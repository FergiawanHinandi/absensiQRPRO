<?php

namespace App\Http\Controllers\Api\V1\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceSettingsController extends Controller
{
    /**
     * Get Settings
     */
    public function index(Request $request)
    {
        $school = $request->user()->school;

        $settings = $school->settings ?? [];
        
        // Merge with defaults if missing
        $defaults = [
            'grace_period_minutes' => 15,
            'qr_expiry_seconds' => 30,
        ];

        return response()->success([
            'grace_period_minutes' => $settings['grace_period_minutes'] ?? $defaults['grace_period_minutes'],
            'qr_expiry_seconds' => $settings['qr_expiry_seconds'] ?? $defaults['qr_expiry_seconds'],
            'school_start_time' => $school->start_time,
            'school_end_time' => $school->end_time,
        ]);
    }

    /**
     * Update Settings
     */
    public function update(Request $request)
    {
        $user = $request->user();
        $school = $user->school;

        $validated = $request->validate([
            'grace_period_minutes' => 'required|integer|min:0',
            'qr_expiry_seconds' => 'required|integer|min:10',
            'school_start_time' => 'required|date_format:H:i:s',
            'school_end_time' => 'required|date_format:H:i:s|after:school_start_time',
        ]);

        DB::transaction(function () use ($school, $validated, $user) {
            // Update main columns
            $school->start_time = $validated['school_start_time'];
            $school->end_time = $validated['school_end_time'];
            
            // Update JSON settings
            $settings = $school->settings ?? [];
            $settings['grace_period_minutes'] = $validated['grace_period_minutes'];
            $settings['qr_expiry_seconds'] = $validated['qr_expiry_seconds'];
            $school->settings = $settings;
            
            $school->save();

            AuditLog::create([
                'school_id' => $school->id,
                'user_id' => $user->id,
                'action' => 'attendance_settings_updated',
                'module' => 'settings',
                'severity' => 'warning', // Changing settings is significant
                'description' => "Updated attendance settings",
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        });

        return response()->success([
            'message' => 'Settings updated successfully'
        ]);
    }
}
