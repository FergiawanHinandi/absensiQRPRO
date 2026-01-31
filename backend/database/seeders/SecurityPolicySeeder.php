<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SecurityPolicySeeder extends Seeder
{
    public function run()
    {
        $policies = [
            [
                'key' => 'attendance.geofence_radius_meters',
                'value' => 50,
                'description' => 'Default geofence radius in meters',
            ],
            [
                'key' => 'attendance.max_scan_per_minute',
                'value' => 30,
                'description' => 'Max attendance scans per minute',
            ],
            [
                'key' => 'attendance.max_failed_scans_per_2min',
                'value' => 5,
                'description' => 'Max failed scans per 2 minutes',
            ],
            [
                'key' => 'attendance.qr_expiry_minutes',
                'value' => 10,
                'description' => 'QR code expiry in minutes',
            ],
            [
                'key' => 'behavior.anomaly_score_suspicious',
                'value' => 3,
                'description' => 'Suspicious anomaly score threshold',
            ],
            [
                'key' => 'behavior.anomaly_score_high',
                'value' => 6,
                'description' => 'High anomaly score threshold',
            ],
            [
                'key' => 'behavior.anomaly_score_critical',
                'value' => 9,
                'description' => 'Critical anomaly score threshold',
            ],
            [
                'key' => 'security.admin_session_max_ip_change',
                'value' => 1,
                'description' => 'Max allowed admin IP changes per session',
            ],
        ];

        foreach ($policies as $policy) {
            DB::table('security_policies')->updateOrInsert(
                [
                    'scope_type' => 'global',
                    'scope_id' => null,
                    'key' => $policy['key'],
                ],
                [
                    'value' => json_encode($policy['value']),
                    'description' => $policy['description'],
                    'updated_by' => 1, // system or super_admin
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
