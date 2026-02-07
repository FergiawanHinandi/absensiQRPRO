<?php

namespace App\Services;

use App\Models\BehaviorBaseline;
use App\Models\BehaviorMetricDaily;
use App\Models\SecurityAlert;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Behavior Anomaly Service
 *
 * Scoring engine for detecting behavioral anomalies.
 * Uses rule-based scoring against established baselines.
 */
class BehaviorAnomalyService
{
    // Scoring rules
    private const SCORE_SCAN_VOLUME_ANOMALY = 2;

    private const SCORE_FAILURE_SPIKE = 2;

    private const SCORE_RAPID_SCANNING = 3;

    private const SCORE_LOCATION_ABUSE = 3;

    private const SCORE_DEVICE_ABUSE = 4;

    private const SCORE_SCHEDULE_ABUSE = 2;

    private const SCORE_QR_REPLAY_ABUSE = 4;

    // Thresholds
    private const THRESHOLD_SCAN_MULTIPLIER = 2.0;    // 2x baseline = anomaly

    private const THRESHOLD_FAILURE_MULTIPLIER = 3.0; // 3x baseline = anomaly

    private const THRESHOLD_RAPID_SCAN_SECONDS = 3;   // < 3 seconds = suspicious

    private const THRESHOLD_OUTSIDE_RADIUS_COUNT = 3;

    private const THRESHOLD_DEVICE_MISMATCH_COUNT = 2;

    private const THRESHOLD_SCHEDULE_MISMATCH_COUNT = 3;

    private const THRESHOLD_QR_REPLAY_COUNT = 2;

    protected BehaviorBaselineService $baselineService;

    protected SecurityAlertService $alertService;

    public function __construct(
        BehaviorBaselineService $baselineService,
        SecurityAlertService $alertService
    ) {
        $this->baselineService = $baselineService;
        $this->alertService = $alertService;
    }

    /**
     * Analyze today's behavior for a user and return risk assessment
     */
    public function analyzeTodayBehavior(int $userId): array
    {
        $user = User::find($userId);
        if (! $user) {
            return $this->emptyResult('User not found');
        }

        // Get today's metrics
        $todayMetrics = BehaviorMetricDaily::forUser($userId)
            ->forDate(today())
            ->first();

        if (! $todayMetrics) {
            return $this->emptyResult('No activity today');
        }

        // Get baseline
        $baseline = $this->baselineService->getBaseline($userId);
        if (! $baseline) {
            $baseline = BehaviorBaseline::getOrCreateForUser($userId, $user->school_id);
        }

        // Run scoring rules
        $scoreBreakdown = $this->calculateScores($todayMetrics, $baseline);
        $totalScore = array_sum(array_column($scoreBreakdown, 'score'));
        $riskLevel = BehaviorBaseline::scoreToRiskLevel($totalScore);

        // Get triggered flags (non-zero scores)
        $triggeredFlags = array_keys(array_filter($scoreBreakdown, fn ($item) => $item['score'] > 0));

        return [
            'user_id' => $userId,
            'user_name' => $user->name,
            'school_id' => $user->school_id,
            'date' => today()->toDateString(),
            'total_score' => $totalScore,
            'risk_level' => $riskLevel,
            'score_breakdown' => $scoreBreakdown,
            'triggered_flags' => $triggeredFlags,
            'metrics' => [
                'today' => [
                    'total_scans' => $todayMetrics->total_scans,
                    'failed_scans' => $todayMetrics->failed_scans,
                    'failed_ratio' => $todayMetrics->failed_ratio,
                    'avg_scan_interval' => $todayMetrics->avg_scan_interval_seconds,
                    'outside_radius_attempts' => $todayMetrics->outside_radius_attempts,
                    'device_mismatch_attempts' => $todayMetrics->device_mismatch_attempts,
                    'schedule_mismatch_attempts' => $todayMetrics->schedule_mismatch_attempts,
                    'qr_replay_attempts' => $todayMetrics->qr_replay_attempts,
                ],
                'baseline' => [
                    'avg_scans_per_day' => $baseline->avg_scans_per_day,
                    'avg_failed_ratio' => $baseline->avg_failed_ratio,
                    'avg_scan_interval' => $baseline->avg_scan_interval_seconds,
                    'days_in_baseline' => $baseline->days_in_baseline,
                ],
            ],
            'baseline_sufficient' => $baseline->hasSufficientData(),
        ];
    }

    /**
     * Calculate scores for each rule
     */
    private function calculateScores(BehaviorMetricDaily $today, BehaviorBaseline $baseline): array
    {
        $scores = [];

        // 1. Scan Volume Anomaly
        $scores['scan_volume_anomaly'] = $this->scoreScanVolume($today, $baseline);

        // 2. Failure Spike
        $scores['failure_spike'] = $this->scoreFailureSpike($today, $baseline);

        // 3. Rapid Scanning Pattern
        $scores['rapid_scanning'] = $this->scoreRapidScanning($today);

        // 4. Location Abuse (Outside Radius)
        $scores['location_abuse'] = $this->scoreLocationAbuse($today);

        // 5. Device Abuse
        $scores['device_abuse'] = $this->scoreDeviceAbuse($today);

        // 6. Schedule Abuse
        $scores['schedule_abuse'] = $this->scoreScheduleAbuse($today);

        // 7. QR Replay Abuse
        $scores['qr_replay_abuse'] = $this->scoreQrReplayAbuse($today);

        return $scores;
    }

    /**
     * Rule 1: Scan volume significantly higher than baseline
     */
    private function scoreScanVolume(BehaviorMetricDaily $today, BehaviorBaseline $baseline): array
    {
        $triggered = false;
        $details = [];

        if ($baseline->avg_scans_per_day > 0) {
            $ratio = $today->total_scans / $baseline->avg_scans_per_day;
            $triggered = $ratio >= self::THRESHOLD_SCAN_MULTIPLIER;
            $details = [
                'today_scans' => $today->total_scans,
                'baseline_avg' => $baseline->avg_scans_per_day,
                'ratio' => round($ratio, 2),
                'threshold' => self::THRESHOLD_SCAN_MULTIPLIER,
            ];
        } elseif ($today->total_scans > 50) {
            // No baseline but unusually high volume
            $triggered = true;
            $details = [
                'today_scans' => $today->total_scans,
                'reason' => 'High volume with no baseline',
            ];
        }

        return [
            'score' => $triggered ? self::SCORE_SCAN_VOLUME_ANOMALY : 0,
            'triggered' => $triggered,
            'details' => $details,
        ];
    }

    /**
     * Rule 2: Failure ratio spike
     */
    private function scoreFailureSpike(BehaviorMetricDaily $today, BehaviorBaseline $baseline): array
    {
        $triggered = false;
        $details = [];

        $todayFailedRatio = $today->failed_ratio;

        if ($baseline->avg_failed_ratio > 0) {
            $ratio = $todayFailedRatio / $baseline->avg_failed_ratio;
            $triggered = $ratio >= self::THRESHOLD_FAILURE_MULTIPLIER;
            $details = [
                'today_failed_ratio' => $todayFailedRatio,
                'baseline_avg' => $baseline->avg_failed_ratio,
                'ratio' => round($ratio, 2),
                'threshold' => self::THRESHOLD_FAILURE_MULTIPLIER,
            ];
        } elseif ($todayFailedRatio > 0.3) {
            // No baseline but high failure rate (>30%)
            $triggered = true;
            $details = [
                'today_failed_ratio' => $todayFailedRatio,
                'reason' => 'High failure rate with no baseline',
            ];
        }

        return [
            'score' => $triggered ? self::SCORE_FAILURE_SPIKE : 0,
            'triggered' => $triggered,
            'details' => $details,
        ];
    }

    /**
     * Rule 3: Rapid scanning pattern (automated scanning)
     */
    private function scoreRapidScanning(BehaviorMetricDaily $today): array
    {
        $triggered = $today->avg_scan_interval_seconds !== null
                  && $today->avg_scan_interval_seconds < self::THRESHOLD_RAPID_SCAN_SECONDS
                  && $today->total_scans >= 5; // Need enough scans to be meaningful

        return [
            'score' => $triggered ? self::SCORE_RAPID_SCANNING : 0,
            'triggered' => $triggered,
            'details' => [
                'avg_interval_seconds' => $today->avg_scan_interval_seconds,
                'min_interval_seconds' => $today->min_scan_interval_seconds,
                'threshold' => self::THRESHOLD_RAPID_SCAN_SECONDS,
                'total_scans' => $today->total_scans,
            ],
        ];
    }

    /**
     * Rule 4: Location abuse (multiple outside radius attempts)
     */
    private function scoreLocationAbuse(BehaviorMetricDaily $today): array
    {
        $triggered = $today->outside_radius_attempts >= self::THRESHOLD_OUTSIDE_RADIUS_COUNT;

        return [
            'score' => $triggered ? self::SCORE_LOCATION_ABUSE : 0,
            'triggered' => $triggered,
            'details' => [
                'attempts' => $today->outside_radius_attempts,
                'threshold' => self::THRESHOLD_OUTSIDE_RADIUS_COUNT,
                'max_distance' => $today->max_distance_from_school,
            ],
        ];
    }

    /**
     * Rule 5: Device abuse (multiple device mismatch attempts)
     */
    private function scoreDeviceAbuse(BehaviorMetricDaily $today): array
    {
        $triggered = $today->device_mismatch_attempts >= self::THRESHOLD_DEVICE_MISMATCH_COUNT;

        return [
            'score' => $triggered ? self::SCORE_DEVICE_ABUSE : 0,
            'triggered' => $triggered,
            'details' => [
                'attempts' => $today->device_mismatch_attempts,
                'threshold' => self::THRESHOLD_DEVICE_MISMATCH_COUNT,
                'unique_devices' => $today->unique_devices_used,
            ],
        ];
    }

    /**
     * Rule 6: Schedule abuse (accessing unauthorized schedules)
     */
    private function scoreScheduleAbuse(BehaviorMetricDaily $today): array
    {
        $triggered = $today->schedule_mismatch_attempts >= self::THRESHOLD_SCHEDULE_MISMATCH_COUNT;

        return [
            'score' => $triggered ? self::SCORE_SCHEDULE_ABUSE : 0,
            'triggered' => $triggered,
            'details' => [
                'attempts' => $today->schedule_mismatch_attempts,
                'threshold' => self::THRESHOLD_SCHEDULE_MISMATCH_COUNT,
            ],
        ];
    }

    /**
     * Rule 7: QR replay abuse
     */
    private function scoreQrReplayAbuse(BehaviorMetricDaily $today): array
    {
        $triggered = $today->qr_replay_attempts >= self::THRESHOLD_QR_REPLAY_COUNT;

        return [
            'score' => $triggered ? self::SCORE_QR_REPLAY_ABUSE : 0,
            'triggered' => $triggered,
            'details' => [
                'attempts' => $today->qr_replay_attempts,
                'threshold' => self::THRESHOLD_QR_REPLAY_COUNT,
            ],
        ];
    }

    /**
     * Process analysis and create alerts if needed
     */
    public function processAndAlert(int $userId): array
    {
        $analysis = $this->analyzeTodayBehavior($userId);

        if ($analysis['total_score'] === null) {
            return $analysis;
        }

        // Update baseline with risk assessment
        $baseline = BehaviorBaseline::forUser($userId)->first();
        if ($baseline) {
            $baseline->updateRiskAssessment(
                $analysis['total_score'],
                $analysis['score_breakdown']
            );

            // Handle escalation for risky behavior
            if ($analysis['risk_level'] !== BehaviorBaseline::RISK_NORMAL) {
                $this->handleEscalation($userId, $analysis, $baseline);
            }
        }

        return $analysis;
    }

    /**
     * Handle escalation based on risk level
     */
    private function handleEscalation(int $userId, array $analysis, BehaviorBaseline $baseline): void
    {
        $user = User::find($userId);
        if (! $user) {
            return;
        }

        // Create security alert for suspicious+ behavior
        if (in_array($analysis['risk_level'], [
            BehaviorBaseline::RISK_SUSPICIOUS,
            BehaviorBaseline::RISK_HIGH,
            BehaviorBaseline::RISK_CRITICAL,
        ])) {
            $this->createBehaviorAlert($user, $analysis);
        }

        // Additional actions for critical risk
        if ($analysis['risk_level'] === BehaviorBaseline::RISK_CRITICAL) {
            $this->handleCriticalRisk($user, $analysis, $baseline);
        }
    }

    /**
     * Create a security alert for behavior anomaly
     */
    private function createBehaviorAlert(User $user, array $analysis): ?SecurityAlert
    {
        $severity = match ($analysis['risk_level']) {
            BehaviorBaseline::RISK_CRITICAL => SecurityAlertService::SEVERITY_CRITICAL,
            BehaviorBaseline::RISK_HIGH => SecurityAlertService::SEVERITY_HIGH,
            BehaviorBaseline::RISK_SUSPICIOUS => SecurityAlertService::SEVERITY_MEDIUM,
            default => SecurityAlertService::SEVERITY_LOW,
        };

        $topFlags = array_slice($analysis['triggered_flags'], 0, 3);
        $flagsStr = implode(', ', $topFlags);

        return $this->alertService->createAlert(
            'behavior_anomaly_detected',
            $severity,
            "Behavioral anomaly detected for {$user->name}: {$flagsStr}",
            [
                'total_score' => $analysis['total_score'],
                'risk_level' => $analysis['risk_level'],
                'triggered_flags' => $analysis['triggered_flags'],
                'score_breakdown' => $analysis['score_breakdown'],
                'metrics' => $analysis['metrics'],
            ],
            $user->id,
            $user->school_id,
            null,
            null,
            $analysis['risk_level'] === BehaviorBaseline::RISK_CRITICAL // Force notify for critical
        );
    }

    /**
     * Handle critical risk level - immediate escalation
     */
    private function handleCriticalRisk(User $user, array $analysis, BehaviorBaseline $baseline): void
    {
        Log::channel('security')->critical('CRITICAL BEHAVIOR RISK', [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'school_id' => $user->school_id,
            'score' => $analysis['total_score'],
            'flags' => $analysis['triggered_flags'],
        ]);

        // 1. Flag teacher account for admin review
        $baseline->flagForReview();

        // 2. Require device re-verification on next login
        $baseline->requireDeviceReverification();

        // 3. Send immediate Telegram alert (handled by SecurityAlertService with force notify)
        // The alert created above with force_notify = true will trigger immediate notification

        // 4. Auto-generate security investigation report
        if (in_array($user->role_type, ['teacher', 'homeroom_teacher'])) {
            \App\Jobs\GenerateSecurityReportJob::dispatch(
                $user->id,
                '7d',
                'critical_behavior_risk_detected'
            );
        }
    }

    /**
     * Return empty result structure
     */
    private function emptyResult(string $reason): array
    {
        return [
            'user_id' => null,
            'total_score' => null,
            'risk_level' => null,
            'score_breakdown' => [],
            'triggered_flags' => [],
            'reason' => $reason,
        ];
    }

    /**
     * Batch analyze all teachers in a school
     */
    public function analyzeSchool(int $schoolId): array
    {
        $teachers = User::where('school_id', $schoolId)
            ->where('role_type', 'teacher')
            ->where('is_active', true)
            ->get();

        $results = [];
        foreach ($teachers as $teacher) {
            $results[$teacher->id] = $this->processAndAlert($teacher->id);
        }

        return $results;
    }

    /**
     * Get summary of at-risk teachers in a school
     */
    public function getSchoolRiskSummary(int $schoolId): array
    {
        $baselines = BehaviorBaseline::forSchool($schoolId)
            ->atRisk()
            ->with(['user:id,name,email'])
            ->orderByRaw("FIELD(current_risk_level, 'critical', 'high', 'suspicious')")
            ->orderBy('current_risk_score', 'desc')
            ->get();

        return $baselines->map(function ($baseline) {
            return [
                'user_id' => $baseline->user_id,
                'teacher_name' => $baseline->user?->name,
                'email' => $baseline->user?->email,
                'risk_level' => $baseline->current_risk_level,
                'score' => $baseline->current_risk_score,
                'top_flags' => array_keys($baseline->getTopRiskFactors()),
                'last_assessment' => $baseline->last_risk_assessment?->toIso8601String(),
                'flagged_for_review' => $baseline->flagged_for_review,
                'requires_reverification' => $baseline->requires_device_reverification,
            ];
        })->toArray();
    }
}
