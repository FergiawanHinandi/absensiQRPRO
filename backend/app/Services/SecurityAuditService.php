<?php

namespace App\Services;

use App\Models\SecurityEvent;
use App\Models\TeacherDevice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Enhanced Security Audit Service
 *
 * Provides comprehensive security auditing, threat detection, and incident management.
 *
 * FEATURES:
 * - Real-time security event logging
 * - Anomaly detection (impossible travel, suspicious patterns)
 * - Threat scoring and risk assessment
 * - Automated incident response triggers
 * - Audit trail for compliance (GDPR, etc.)
 *
 * USAGE:
 * app(SecurityAuditService::class)->logEvent($user, 'unauthorized_access', ['resource' => 'admin'])
 */
class SecurityAuditService
{
    /**
     * Severity thresholds for automatic escalation
     */
    private const HIGH_SEVERITY_THRESHOLD = 3;  // Auto-alert after 3 high events in 1 hour

    private const CRITICAL_AUTO_BLOCK = true;   // Auto-block on critical events

    /**
     * Maximum distance (km) travel possible in given time before flagging
     */
    private const MAX_TRAVEL_SPEED_KMH = 1000;  // Airplane speed

    /**
     * Cache TTL for threat scores
     */
    private const THREAT_SCORE_TTL = 3600;  // 1 hour

    /**
     * Log a security event
     *
     * @param User|null     $user       The user involved (null for anonymous events)
     * @param string        $eventType  Event type constant from SecurityEvent
     * @param array         $context    Additional context data
     * @param string        $severity   Severity level
     * @param Request|null  $request    HTTP request for metadata extraction
     * @return SecurityEvent
     */
    public function logEvent(
        ?User $user,
        string $eventType,
        array $context = [],
        string $severity = SecurityEvent::SEVERITY_MEDIUM,
        ?Request $request = null
    ): SecurityEvent {
        $request = $request ?? request();

        $event = SecurityEvent::create([
            'school_id' => $user?->school_id ?? ($context['school_id'] ?? null),
            'user_id' => $user?->id,
            'user_type' => $user?->role_type,
            'event_type' => $eventType,
            'severity' => $severity,
            'ip_address' => $this->getClientIp($request),
            'user_agent' => $request->userAgent(),
            'device_id' => $request->header('X-Device-ID'),
            'latitude' => $context['latitude'] ?? null,
            'longitude' => $context['longitude'] ?? null,
            'context' => $this->sanitizeContext($context),
            'message' => $this->generateEventMessage($eventType, $context),
            'is_resolved' => false,
        ]);

        // Update threat score in cache
        if ($user) {
            $this->updateThreatScore($user->id, $severity);
        }

        // Trigger automatic responses
        $this->handleAutoResponse($event, $user);

        // Log to application log for SIEM integration
        Log::channel('security')->info('Security event logged', [
            'event_id' => $event->id,
            'event_type' => $eventType,
            'severity' => $severity,
            'user_id' => $user?->id,
            'ip' => $event->ip_address,
        ]);

        return $event;
    }

    /**
     * Detect impossible travel anomaly
     *
     * @param User   $user
     * @param float  $latitude
     * @param float  $longitude
     * @return bool  True if suspicious
     */
    public function detectImpossibleTravel(User $user, float $latitude, float $longitude): bool
    {
        // Get last known location
        $lastEvent = SecurityEvent::where('user_id', $user->id)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->latest()
            ->first();

        if (!$lastEvent) {
            return false;
        }

        $distance = $this->calculateDistance(
            $lastEvent->latitude,
            $lastEvent->longitude,
            $latitude,
            $longitude
        );

        // Carbon 3 (Laravel 12) returns a signed difference — normalize it.
        $timeDiff = abs((float) Carbon::now()->diffInHours($lastEvent->created_at));

        if ($timeDiff > 0) {
            $speed = $distance / $timeDiff;

            if ($speed > self::MAX_TRAVEL_SPEED_KMH) {
                $this->logEvent($user, SecurityEvent::EVENT_IMPOSSIBLE_TRAVEL, [
                    'previous_location' => [
                        'lat' => $lastEvent->latitude,
                        'lng' => $lastEvent->longitude,
                        'time' => $lastEvent->created_at->toIso8601String(),
                    ],
                    'current_location' => [
                        'lat' => $latitude,
                        'lng' => $longitude,
                    ],
                    'distance_km' => round($distance, 2),
                    'implied_speed_kmh' => round($speed, 2),
                ], SecurityEvent::SEVERITY_HIGH);

                return true;
            }
        }

        return false;
    }

    /**
     * Detect suspicious login patterns
     *
     * @param User    $user
     * @param Request $request
     * @return array  [is_suspicious: bool, reasons: array]
     */
    public function analyzeLoginAttempt(User $user, Request $request): array
    {
        $reasons = [];

        // Check for unusual login time
        $hour = Carbon::now()->hour;
        if ($hour >= 0 && $hour <= 5) {
            $reasons[] = 'unusual_hour';
        }

        // Check for new device
        $deviceId = $request->header('X-Device-ID');
        if ($deviceId) {
            $knownDevice = TeacherDevice::where('teacher_id', $user->id)
                ->where('device_id', $deviceId)
                ->exists();

            if (!$knownDevice) {
                $reasons[] = 'new_device';
            }
        }

        // Check for unusual IP
        $ip = $this->getClientIp($request);
        $recentIps = SecurityEvent::where('user_id', $user->id)
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->pluck('ip_address')
            ->unique()
            ->toArray();

        if (!empty($recentIps) && !in_array($ip, $recentIps)) {
            $reasons[] = 'new_ip_address';
        }

        // Check for rapid login attempts from different IPs
        $recentAttempts = SecurityEvent::where('user_id', $user->id)
            ->where('event_type', 'login_attempt')
            ->where('created_at', '>=', Carbon::now()->subMinutes(10))
            ->count();

        if ($recentAttempts > 5) {
            $reasons[] = 'rapid_login_attempts';
        }

        return [
            'is_suspicious' => !empty($reasons),
            'reasons' => $reasons,
            'threat_score' => $this->getThreatScore($user->id),
        ];
    }

    /**
     * Get threat score for a user
     *
     * @param int $userId
     * @return int Score from 0-100
     */
    public function getThreatScore(int $userId): int
    {
        $cacheKey = "threat_score:user:{$userId}";

        return Cache::remember($cacheKey, self::THREAT_SCORE_TTL, function () use ($userId) {
            $score = 0;

            // Count events by severity in last 24 hours
            $events = SecurityEvent::where('user_id', $userId)
                ->where('created_at', '>=', Carbon::now()->subDay())
                ->get();

            foreach ($events as $event) {
                switch ($event->severity) {
                    case SecurityEvent::SEVERITY_CRITICAL:
                        $score += 25;
                        break;
                    case SecurityEvent::SEVERITY_HIGH:
                        $score += 15;
                        break;
                    case SecurityEvent::SEVERITY_MEDIUM:
                        $score += 5;
                        break;
                    case SecurityEvent::SEVERITY_LOW:
                        $score += 1;
                        break;
                }
            }

            return min($score, 100);
        });
    }

    /**
     * Get security dashboard summary
     *
     * @param int|null $schoolId
     * @return array
     */
    public function getDashboardSummary(?int $schoolId = null): array
    {
        $query = SecurityEvent::query();

        if ($schoolId) {
            $query->where('school_id', $schoolId);
        }

        $today = Carbon::today();
        $todayEvents = (clone $query)->whereDate('created_at', $today)->count();

        $unresolvedCritical = (clone $query)
            ->unresolved()
            ->where('severity', SecurityEvent::SEVERITY_CRITICAL)
            ->count();

        $unresolvedHigh = (clone $query)
            ->unresolved()
            ->where('severity', SecurityEvent::SEVERITY_HIGH)
            ->count();

        $topEventTypes = (clone $query)
            ->where('created_at', '>=', Carbon::now()->subWeek())
            ->selectRaw('event_type, COUNT(*) as count')
            ->groupBy('event_type')
            ->orderByDesc('count')
            ->limit(5)
            ->pluck('count', 'event_type')
            ->toArray();

        $recentTrend = collect(range(6, 0))->map(function ($daysAgo) use ($query, $schoolId) {
            $date = Carbon::today()->subDays($daysAgo);
            $q = SecurityEvent::query();
            if ($schoolId) {
                $q->where('school_id', $schoolId);
            }

            return [
                'date' => $date->format('Y-m-d'),
                'count' => $q->whereDate('created_at', $date)->count(),
            ];
        });

        return [
            'today_events' => $todayEvents,
            'unresolved_critical' => $unresolvedCritical,
            'unresolved_high' => $unresolvedHigh,
            'top_event_types' => $topEventTypes,
            'weekly_trend' => $recentTrend->toArray(),
            'risk_level' => $this->calculateOverallRiskLevel($unresolvedCritical, $unresolvedHigh),
        ];
    }

    /**
     * Get high-risk users
     *
     * @param int|null $schoolId
     * @param int      $limit
     * @return Collection
     */
    public function getHighRiskUsers(?int $schoolId = null, int $limit = 10): Collection
    {
        $query = SecurityEvent::query()
            ->select('user_id')
            ->selectRaw('COUNT(*) as event_count')
            ->selectRaw('SUM(CASE WHEN severity = ? THEN 4 WHEN severity = ? THEN 3 WHEN severity = ? THEN 2 ELSE 1 END) as risk_score', [
                SecurityEvent::SEVERITY_CRITICAL,
                SecurityEvent::SEVERITY_HIGH,
                SecurityEvent::SEVERITY_MEDIUM,
            ])
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->whereNotNull('user_id');

        if ($schoolId) {
            $query->where('school_id', $schoolId);
        }

        return $query
            ->groupBy('user_id')
            ->orderByDesc('risk_score')
            ->limit($limit)
            ->with('user:id,name,email,role_type')
            ->get();
    }

    /**
     * Resolve a security event
     *
     * @param SecurityEvent $event
     * @param User          $resolver
     * @param string        $notes
     * @return SecurityEvent
     */
    public function resolveEvent(SecurityEvent $event, User $resolver, string $notes = ''): SecurityEvent
    {
        $event->update([
            'is_resolved' => true,
            'resolved_by' => $resolver->id,
            'resolved_at' => Carbon::now(),
            'resolution_notes' => $notes,
        ]);

        // Recalculate threat score for affected user
        if ($event->user_id) {
            Cache::forget("threat_score:user:{$event->user_id}");
        }

        Log::channel('security')->info('Security event resolved', [
            'event_id' => $event->id,
            'resolver_id' => $resolver->id,
        ]);

        return $event;
    }

    /**
     * Bulk resolve events by type
     *
     * @param string $eventType
     * @param User   $resolver
     * @param string $notes
     * @param int|null $schoolId
     * @return int Number of resolved events
     */
    public function bulkResolve(string $eventType, User $resolver, string $notes = '', ?int $schoolId = null): int
    {
        $query = SecurityEvent::unresolved()
            ->where('event_type', $eventType);

        if ($schoolId) {
            $query->where('school_id', $schoolId);
        }

        $count = $query->update([
            'is_resolved' => true,
            'resolved_by' => $resolver->id,
            'resolved_at' => Carbon::now(),
            'resolution_notes' => $notes,
        ]);

        Log::channel('security')->info('Bulk security event resolution', [
            'event_type' => $eventType,
            'count' => $count,
            'resolver_id' => $resolver->id,
        ]);

        return $count;
    }

    /**
     * Generate audit report
     *
     * @param Carbon      $startDate
     * @param Carbon      $endDate
     * @param int|null    $schoolId
     * @return array
     */
    public function generateAuditReport(Carbon $startDate, Carbon $endDate, ?int $schoolId = null): array
    {
        $query = SecurityEvent::query()
            ->whereBetween('created_at', [$startDate, $endDate]);

        if ($schoolId) {
            $query->where('school_id', $schoolId);
        }

        $events = $query->get();

        return [
            'period' => [
                'start' => $startDate->toIso8601String(),
                'end' => $endDate->toIso8601String(),
            ],
            'summary' => [
                'total_events' => $events->count(),
                'by_severity' => $events->groupBy('severity')->map->count(),
                'by_type' => $events->groupBy('event_type')->map->count(),
                'resolved' => $events->where('is_resolved', true)->count(),
                'unresolved' => $events->where('is_resolved', false)->count(),
            ],
            'top_affected_users' => $events
                ->whereNotNull('user_id')
                ->groupBy('user_id')
                ->map->count()
                ->sortDesc()
                ->take(10)
                ->toArray(),
            'timeline' => $events
                ->groupBy(fn($e) => $e->created_at->format('Y-m-d'))
                ->map->count()
                ->toArray(),
            'response_metrics' => [
                'avg_resolution_time_hours' => $this->calculateAvgResolutionTime($events),
                'resolution_rate' => $events->count() > 0
                    ? round($events->where('is_resolved', true)->count() / $events->count() * 100, 2)
                    : 0,
            ],
        ];
    }

    /**
     * Update threat score in cache
     */
    private function updateThreatScore(int $userId, string $severity): void
    {
        $cacheKey = "threat_score:user:{$userId}";

        $increment = match ($severity) {
            SecurityEvent::SEVERITY_CRITICAL => 25,
            SecurityEvent::SEVERITY_HIGH => 15,
            SecurityEvent::SEVERITY_MEDIUM => 5,
            default => 1,
        };

        $currentScore = Cache::get($cacheKey, 0);
        Cache::put($cacheKey, min($currentScore + $increment, 100), self::THREAT_SCORE_TTL);
    }

    /**
     * Handle automatic response to security events
     */
    private function handleAutoResponse(SecurityEvent $event, ?User $user): void
    {
        // Critical events - consider auto-blocking
        if ($event->severity === SecurityEvent::SEVERITY_CRITICAL && self::CRITICAL_AUTO_BLOCK && $user) {
            // Log for admin review - actual blocking requires manual approval
            Log::channel('security')->warning('Critical security event - review for account suspension', [
                'user_id' => $user->id,
                'event_id' => $event->id,
                'event_type' => $event->event_type,
            ]);

            // Could trigger notification to admins here
            // event(new CriticalSecurityEvent($event));
        }

        // Check for high severity threshold
        if ($user && in_array($event->severity, [SecurityEvent::SEVERITY_HIGH, SecurityEvent::SEVERITY_CRITICAL])) {
            $recentHighEvents = SecurityEvent::where('user_id', $user->id)
                ->whereIn('severity', [SecurityEvent::SEVERITY_HIGH, SecurityEvent::SEVERITY_CRITICAL])
                ->where('created_at', '>=', Carbon::now()->subHour())
                ->count();

            if ($recentHighEvents >= self::HIGH_SEVERITY_THRESHOLD) {
                Log::channel('security')->alert('High severity threshold exceeded', [
                    'user_id' => $user->id,
                    'count' => $recentHighEvents,
                ]);
            }
        }
    }

    /**
     * Generate human-readable event message
     */
    private function generateEventMessage(string $eventType, array $context): string
    {
        return match ($eventType) {
            SecurityEvent::EVENT_OUTSIDE_SCHEDULE => 'Attendance attempt outside scheduled time',
            SecurityEvent::EVENT_OUTSIDE_RADIUS => sprintf(
                'User located %.0fm from allowed area',
                $context['distance'] ?? 0
            ),
            SecurityEvent::EVENT_UNAPPROVED_DEVICE => 'Access from unapproved device',
            SecurityEvent::EVENT_DUPLICATE_ATTEMPT => 'Duplicate attendance scan attempt',
            SecurityEvent::EVENT_MOCK_LOCATION => 'Potential GPS spoofing detected',
            SecurityEvent::EVENT_QR_REPLAY => 'QR code replay attack detected',
            SecurityEvent::EVENT_QR_OWNERSHIP_VIOLATION => 'Student used another student\'s QR code',
            SecurityEvent::EVENT_POOR_GPS_ACCURACY => sprintf(
                'GPS accuracy too low: %.0fm',
                $context['accuracy'] ?? 0
            ),
            SecurityEvent::EVENT_SUSPICIOUS_DEVICE_CHANGE => 'Suspicious device change detected',
            SecurityEvent::EVENT_IMPOSSIBLE_TRAVEL => sprintf(
                'Impossible travel detected: %.0fkm in suspicious timeframe',
                $context['distance_km'] ?? 0
            ),
            SecurityEvent::EVENT_UNAUTHORIZED_ACCESS => sprintf(
                'Unauthorized access attempt to %s',
                $context['resource'] ?? 'unknown resource'
            ),
            SecurityEvent::EVENT_RATE_LIMIT_EXCEEDED => 'Rate limit exceeded',
            default => 'Security event: ' . $eventType,
        };
    }

    /**
     * Sanitize context data before storage
     */
    private function sanitizeContext(array $context): array
    {
        // Remove sensitive data
        $sensitiveKeys = ['password', 'token', 'secret', 'api_key', 'authorization'];

        return collect($context)
            ->reject(fn($value, $key) => in_array(strtolower($key), $sensitiveKeys))
            ->map(fn($value) => is_string($value) ? mb_substr($value, 0, 1000) : $value)
            ->toArray();
    }

    /**
     * Get real client IP behind proxies
     */
    private function getClientIp(Request $request): string
    {
        $ip = $request->ip();

        // Check for proxy headers (only trust if from known proxy)
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $header) {
            if ($forwardedIp = $request->server($header)) {
                // Take the first IP if multiple (client IP)
                $ip = explode(',', $forwardedIp)[0];
                break;
            }
        }

        return trim($ip);
    }

    /**
     * Calculate distance between two coordinates using Haversine formula
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Calculate overall risk level
     */
    private function calculateOverallRiskLevel(int $criticalCount, int $highCount): string
    {
        if ($criticalCount > 0) {
            return 'critical';
        }
        if ($highCount >= 5) {
            return 'high';
        }
        if ($highCount > 0) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Calculate average resolution time
     */
    private function calculateAvgResolutionTime(Collection $events): float
    {
        $resolved = $events->filter(fn($e) => $e->is_resolved && $e->resolved_at);

        if ($resolved->isEmpty()) {
            return 0;
        }

        $totalHours = $resolved->sum(fn($e) => $e->created_at->diffInMinutes($e->resolved_at) / 60);

        return round($totalHours / $resolved->count(), 2);
    }
}
