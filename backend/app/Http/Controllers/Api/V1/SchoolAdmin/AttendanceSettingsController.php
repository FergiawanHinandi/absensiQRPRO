<?php

namespace App\Http\Controllers\Api\V1\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceSettingsController extends Controller
{
    /**
     * Get Settings (General)
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
     * Update Settings (General)
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
                'description' => 'Updated attendance settings (General)',
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        });

        return response()->success([
            'message' => 'Settings updated successfully',
        ]);
    }

    /**
     * Get QR Mode Settings
     */
    public function getQrMode(Request $request)
    {
        $settings = $request->user()->school->settings ?? [];

        return response()->success([
            'qr_expiry_seconds' => $settings['qr_expiry_seconds'] ?? config('school_settings.qr_expiry_seconds.default', 30),
            'qr_regeneration_cooldown' => $settings['qr_regeneration_cooldown'] ?? config('school_settings.qr_regeneration_cooldown.default', 5),
        ]);
    }

    /**
     * Update QR Mode Settings
     */
    public function updateQrMode(Request $request)
    {
        $validated = $request->validate([
            'qr_expiry_seconds' => 'required|integer|min:5|max:60',
            'qr_regeneration_cooldown' => 'required|integer|min:0|max:30',
        ]);

        $this->updateSettings($request->user(), $validated, 'Updated QR Mode settings');

        return response()->success(['message' => 'QR Mode settings updated']);
    }

    /**
     * Get Override Settings
     */
    public function getOverride(Request $request)
    {
        $settings = $request->user()->school->settings ?? [];

        return response()->success([
            'allow_teacher_override' => $settings['allow_teacher_override'] ?? config('school_settings.allow_teacher_override.default', true),
            'require_override_reason' => $settings['require_override_reason'] ?? config('school_settings.require_override_reason.default', true),
        ]);
    }

    /**
     * Update Override Settings
     */
    public function updateOverride(Request $request)
    {
        $validated = $request->validate([
            'allow_teacher_override' => 'required|boolean',
            'require_override_reason' => 'required|boolean',
        ]);

        $this->updateSettings($request->user(), $validated, 'Updated Override settings');

        return response()->success(['message' => 'Override settings updated']);
    }

    /**
     * Get Tolerance Settings
     */
    public function getTolerance(Request $request)
    {
        $settings = $request->user()->school->settings ?? [];

        return response()->success([
            'late_tolerance_minutes' => $settings['late_tolerance_minutes'] ?? config('school_settings.late_tolerance_minutes.default', 15),
            'early_check_in_allowed' => $settings['early_check_in_allowed'] ?? config('school_settings.early_check_in_allowed.default', true),
        ]);
    }

    /**
     * Update Tolerance Settings
     */
    public function updateTolerance(Request $request)
    {
        $validated = $request->validate([
            'late_tolerance_minutes' => 'required|integer|min:0|max:120',
            'early_check_in_allowed' => 'required|boolean',
        ]);

        $this->updateSettings($request->user(), $validated, 'Updated Tolerance settings');

        return response()->success(['message' => 'Tolerance settings updated']);
    }

    /**
     * Helper to update settings JSON
     */
    private function updateSettings($user, array $newSettings, string $logDescription)
    {
        $school = $user->school;

        DB::transaction(function () use ($school, $newSettings, $user, $logDescription) {
            $settings = $school->settings ?? [];

            foreach ($newSettings as $key => $value) {
                $settings[$key] = $value;
            }

            $school->settings = $settings;
            $school->save();

            AuditLog::create([
                'school_id' => $school->id,
                'user_id' => $user->id,
                'action' => 'attendance_settings_updated',
                'module' => 'settings',
                'severity' => 'info',
                'description' => $logDescription,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        });
    }
}
