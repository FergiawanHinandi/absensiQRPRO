<?php

namespace App\Services;

use App\Models\SecurityPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Security Policy Service
 *
 * Provides dynamic, configurable security policies for the multi-tenant
 * school attendance system. Policies can be set globally or per-school.
 *
 * Resolution order:
 * 1. School-specific policy (if $schoolId provided)
 * 2. Global policy
 * 3. Fallback default from DEFAULTS constant
 */
class SecurityPolicyService
{
    /**
     * Cache TTL in seconds (5 minutes).
     */
    protected int $cacheTtl = 300;

    /**
     * Default policy values (fallback if not in database)
     */
    public const DEFAULTS = [
        // Attendance & Geofence
        'attendance.geofence_radius_meters' => 50,
        'attendance.teacher_geofence_radius_meters' => 200,
        'attendance.max_scan_per_minute' => 30,
        'attendance.max_failed_scans_per_2min' => 5,
        'attendance.qr_expiry_minutes' => 10,
        'attendance.schedule_tolerance_before_minutes' => 10,
        'attendance.schedule_tolerance_after_minutes' => 10,
        'attendance.late_threshold_minutes' => 15,

        // Behavior & Anomaly Detection
        'behavior.anomaly_score_suspicious' => 3,
        'behavior.anomaly_score_high' => 6,
        'behavior.anomaly_score_critical' => 9,
        'behavior.max_anomalies_before_flag' => 5,
        'behavior.anomaly_window_hours' => 24,

        // Security & Sessions
        'security.admin_session_max_ip_change' => 1,
        'security.max_devices_per_teacher' => 2,
        'security.session_timeout_minutes' => 480,
        'security.require_device_approval' => true,
        'security.enable_geofence_check' => true,
        'security.enable_impossible_travel_check' => true,

        // Rate Limiting
        'rate_limit.login_attempts' => 5,
        'rate_limit.login_decay_minutes' => 5,
        'rate_limit.api_per_minute' => 60,
        'rate_limit.scan_per_minute' => 10,
        'rate_limit.export_per_hour' => 5,

        // QR Code
        'qr.max_age_hours' => 24,
        'qr.student_card_max_age_days' => 365,
        'qr.nonce_ttl_seconds' => 3600,
    ];

    /**
     * Policy descriptions for documentation
     */
    public const DESCRIPTIONS = [
        'attendance.geofence_radius_meters' => 'Radius geofence untuk siswa dalam meter',
        'attendance.teacher_geofence_radius_meters' => 'Radius geofence untuk guru dalam meter',
        'attendance.max_scan_per_minute' => 'Maksimal scan absensi per menit per sekolah',
        'attendance.max_failed_scans_per_2min' => 'Maksimal scan gagal dalam 2 menit sebelum alert',
        'attendance.qr_expiry_minutes' => 'Masa berlaku QR code dalam menit',
        'attendance.schedule_tolerance_before_minutes' => 'Toleransi waktu sebelum jadwal mulai (menit)',
        'attendance.schedule_tolerance_after_minutes' => 'Toleransi waktu setelah jadwal selesai (menit)',
        'attendance.late_threshold_minutes' => 'Threshold keterlambatan (menit setelah jadwal mulai)',
        'behavior.anomaly_score_suspicious' => 'Skor anomali untuk level "mencurigakan"',
        'behavior.anomaly_score_high' => 'Skor anomali untuk level "tinggi"',
        'behavior.anomaly_score_critical' => 'Skor anomali untuk level "kritis"',
        'behavior.max_anomalies_before_flag' => 'Jumlah anomali sebelum user di-flag',
        'behavior.anomaly_window_hours' => 'Window waktu untuk menghitung anomali (jam)',
        'security.admin_session_max_ip_change' => 'Maksimal perubahan IP per sesi admin',
        'security.max_devices_per_teacher' => 'Maksimal perangkat terdaftar per guru',
        'security.session_timeout_minutes' => 'Timeout sesi dalam menit',
        'security.require_device_approval' => 'Wajib approval perangkat guru',
        'security.enable_geofence_check' => 'Aktifkan validasi geofence',
        'security.enable_impossible_travel_check' => 'Aktifkan deteksi impossible travel',
        'rate_limit.login_attempts' => 'Maksimal percobaan login',
        'rate_limit.login_decay_minutes' => 'Waktu reset rate limit login (menit)',
        'rate_limit.api_per_minute' => 'Maksimal request API per menit',
        'rate_limit.scan_per_minute' => 'Maksimal scan per menit per user',
        'rate_limit.export_per_hour' => 'Maksimal export laporan per jam',
        'qr.max_age_hours' => 'Maksimal umur QR code (jam)',
        'qr.student_card_max_age_days' => 'Masa berlaku kartu QR siswa (hari)',
        'qr.nonce_ttl_seconds' => 'TTL nonce untuk replay protection (detik)',
    ];

    /**
     * Get a security policy value.
     *
     * @param  string  $key  Policy key
     * @param  int|null  $schoolId  School ID for school-specific policy
     * @param  mixed  $default  Custom default (overrides DEFAULTS constant)
     * @return mixed Policy value
     */
    public function get(
        string $key,
        ?int $schoolId = null,
        $default = null,
    ): mixed {
        $cacheKey = $this->cacheKey($key, $schoolId);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use (
            $key,
            $schoolId,
            $default,
        ) {
            // 1. School-specific
            if ($schoolId) {
                $schoolPolicy = SecurityPolicy::forSchool($schoolId)
                    ->byKey($key)
                    ->first();

                if ($schoolPolicy) {
                    return $schoolPolicy->getDecodedValue();
                }
            }

            // 2. Global
            $globalPolicy = SecurityPolicy::global()
                ->byKey($key)
                ->first();

            if ($globalPolicy) {
                return $globalPolicy->getDecodedValue();
            }

            // 3. Fallback to custom default or DEFAULTS constant
            return $default ?? (self::DEFAULTS[$key] ?? null);
        });
    }



    /**
     * Generate cache key for a policy.
     */
    protected function cacheKey(string $key, ?int $schoolId): string
    {
        return "security_policy:{$key}:".($schoolId ?? 'global');
    }

    /**
     * Clear cache for a specific policy key and school.
     */
    public function clearCache(string $key, ?int $schoolId = null): void
    {
        Cache::forget($this->cacheKey($key, $schoolId));

        // Also clear combined cache if school-specific
        if ($schoolId !== null) {
            Cache::forget($this->cacheKey($key, null));
        }
    }

    /**
     * Clear all policy caches for a school
     */
    public function clearSchoolCache(int $schoolId): void
    {
        foreach (array_keys(self::DEFAULTS) as $key) {
            $this->clearCache($key, $schoolId);
        }
    }

    /**
     * Clear all cached policies
     */
    public function clearAllCache(): void
    {
        foreach (array_keys(self::DEFAULTS) as $key) {
            Cache::forget($this->cacheKey($key, null));
        }

        // Clear school-specific caches would require knowing all school IDs
        // Consider using cache tags if available
        Log::info('SecurityPolicyService: All global policy caches cleared');
    }

    // =========================================================================
    // CONVENIENCE METHODS (Type-safe getters for common policies)
    // =========================================================================

    /**
     * Get geofence radius for students
     */
    public function getGeofenceRadius(?int $schoolId = null): int
    {
        return (int) $this->get('attendance.geofence_radius_meters', $schoolId);
    }

    /**
     * Get geofence radius for teachers
     */
    public function getTeacherGeofenceRadius(?int $schoolId = null): int
    {
        return (int) $this->get(
            'attendance.teacher_geofence_radius_meters',
            $schoolId,
        );
    }

    /**
     * Get max scan per minute
     */
    public function getMaxScanPerMinute(?int $schoolId = null): int
    {
        return (int) $this->get('attendance.max_scan_per_minute', $schoolId);
    }

    /**
     * Get max failed scans threshold
     */
    public function getMaxFailedScans(?int $schoolId = null): int
    {
        return (int) $this->get(
            'attendance.max_failed_scans_per_2min',
            $schoolId,
        );
    }

    /**
     * Get QR expiry in minutes
     */
    public function getQrExpiryMinutes(?int $schoolId = null): int
    {
        return (int) $this->get('attendance.qr_expiry_minutes', $schoolId);
    }

    /**
     * Get schedule tolerance before start (minutes)
     */
    public function getScheduleToleranceBefore(?int $schoolId = null): int
    {
        return (int) $this->get(
            'attendance.schedule_tolerance_before_minutes',
            $schoolId,
        );
    }

    /**
     * Get schedule tolerance after end (minutes)
     */
    public function getScheduleToleranceAfter(?int $schoolId = null): int
    {
        return (int) $this->get(
            'attendance.schedule_tolerance_after_minutes',
            $schoolId,
        );
    }

    /**
     * Get late threshold in minutes
     */
    public function getLateThreshold(?int $schoolId = null): int
    {
        return (int) $this->get('attendance.late_threshold_minutes', $schoolId);
    }

    /**
     * Get anomaly score thresholds
     */
    public function getAnomalyThresholds(?int $schoolId = null): array
    {
        return [
            'suspicious' => (int) $this->get(
                'behavior.anomaly_score_suspicious',
                $schoolId,
            ),
            'high' => (int) $this->get(
                'behavior.anomaly_score_high',
                $schoolId,
            ),
            'critical' => (int) $this->get(
                'behavior.anomaly_score_critical',
                $schoolId,
            ),
        ];
    }

    /**
     * Get max admin session IP changes
     */
    public function getMaxSessionIpChanges(?int $schoolId = null): int
    {
        return (int) $this->get(
            'security.admin_session_max_ip_change',
            $schoolId,
        );
    }

    /**
     * Get max devices per teacher
     */
    public function getMaxDevicesPerTeacher(?int $schoolId = null): int
    {
        return (int) $this->get('security.max_devices_per_teacher', $schoolId);
    }

    /**
     * Check if geofence validation is enabled
     */
    public function isGeofenceEnabled(?int $schoolId = null): bool
    {
        return (bool) $this->get('security.enable_geofence_check', $schoolId);
    }

    /**
     * Check if device approval is required
     */
    public function isDeviceApprovalRequired(?int $schoolId = null): bool
    {
        return (bool) $this->get('security.require_device_approval', $schoolId);
    }

    /**
     * Get login rate limit settings
     */
    public function getLoginRateLimit(?int $schoolId = null): array
    {
        return [
            'attempts' => (int) $this->get(
                'rate_limit.login_attempts',
                $schoolId,
            ),
            'decay_minutes' => (int) $this->get(
                'rate_limit.login_decay_minutes',
                $schoolId,
            ),
        ];
    }

    /**
     * Get API rate limit per minute
     */
    public function getApiRateLimit(?int $schoolId = null): int
    {
        return (int) $this->get('rate_limit.api_per_minute', $schoolId);
    }

    /**
     * Get scan rate limit per minute
     */
    public function getScanRateLimit(?int $schoolId = null): int
    {
        return (int) $this->get('rate_limit.scan_per_minute', $schoolId);
    }

    // =========================================================================
    // ADMIN METHODS
    // =========================================================================

    /**
     * Get all policies with their current values for a school
     */
    public function getAllPolicies(?int $schoolId = null): array
    {
        $policies = [];

        foreach (self::DEFAULTS as $key => $default) {
            $policies[$key] = [
                'key' => $key,
                'value' => $this->get($key, $schoolId),
                'default' => $default,
                'description' => self::DESCRIPTIONS[$key] ?? null,
                'scope' => $this->getPolicyScope($key, $schoolId),
            ];
        }

        return $policies;
    }

    /**
     * Get the scope of a policy (global, school, or default)
     */
    protected function getPolicyScope(string $key, ?int $schoolId): string
    {
        if ($schoolId) {
            $schoolPolicy = SecurityPolicy::forSchool($schoolId)
                ->byKey($key)
                ->exists();

            if ($schoolPolicy) {
                return 'school';
            }
        }

        $globalPolicy = SecurityPolicy::global()
            ->byKey($key)
            ->exists();

        return $globalPolicy ? 'global' : 'default';
    }

    /**
     * Set a policy value
     */
    public function set(
        string $key,
        mixed $value,
        ?int $schoolId,
        int $updatedBy,
    ): bool {
        $scopeType = $schoolId ? 'school' : 'global';

        $policy = SecurityPolicy::updateOrCreate(
            [
                'scope_type' => $scopeType,
                'scope_id' => $schoolId,
                'key' => $key,
            ],
            [
                'value' => json_encode($value),
                'description' => self::DESCRIPTIONS[$key] ?? null,
                'updated_by' => $updatedBy,
            ],
        );

        // Clear cache
        $this->clearCache($key, $schoolId);

        Log::info('SecurityPolicy updated', [
            'key' => $key,
            'value' => $value,
            'scope_type' => $scopeType,
            'scope_id' => $schoolId,
            'updated_by' => $updatedBy,
        ]);

        return $policy->wasRecentlyCreated || $policy->wasChanged();
    }

    /**
     * Delete a policy (revert to default/global)
     */
    public function delete(string $key, ?int $schoolId): bool
    {
        $query = SecurityPolicy::where('key', $key)
            ->where('scope_type', $schoolId ? 'school' : 'global');

        if ($schoolId) {
            $query->where('scope_id', $schoolId);
        } else {
            $query->whereNull('scope_id');
        }

        $result = $query->delete();

        $this->clearCache($key, $schoolId);

        return $result > 0;
    }

    /**
     * Validate a policy value
     */
    public function validate(string $key, mixed $value): array
    {
        $errors = [];

        // Numeric validations
        $numericKeys = [
            'attendance.geofence_radius_meters' => ['min' => 10, 'max' => 5000],
            'attendance.teacher_geofence_radius_meters' => [
                'min' => 10,
                'max' => 5000,
            ],
            'attendance.max_scan_per_minute' => ['min' => 1, 'max' => 1000],
            'attendance.max_failed_scans_per_2min' => [
                'min' => 1,
                'max' => 100,
            ],
            'attendance.qr_expiry_minutes' => ['min' => 1, 'max' => 60],
            'behavior.anomaly_score_suspicious' => ['min' => 1, 'max' => 10],
            'behavior.anomaly_score_high' => ['min' => 1, 'max' => 20],
            'behavior.anomaly_score_critical' => ['min' => 1, 'max' => 30],
            'rate_limit.login_attempts' => ['min' => 1, 'max' => 20],
            'rate_limit.api_per_minute' => ['min' => 10, 'max' => 1000],
        ];

        if (isset($numericKeys[$key])) {
            $rules = $numericKeys[$key];
            if (! is_numeric($value)) {
                $errors[] = 'Value must be numeric';
            } elseif ($value < $rules['min'] || $value > $rules['max']) {
                $errors[] = "Value must be between {$rules['min']} and {$rules['max']}";
            }
        }

        // Boolean validations
        $booleanKeys = [
            'security.require_device_approval',
            'security.enable_geofence_check',
            'security.enable_impossible_travel_check',
        ];

        if (in_array($key, $booleanKeys) && ! is_bool($value)) {
            $errors[] = 'Value must be boolean';
        }

        return $errors;
    }
}
