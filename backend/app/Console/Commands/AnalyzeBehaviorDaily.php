<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\BehaviorMetricDaily;
use App\Models\SecurityAlert;
use App\Models\User;
use App\Services\BehaviorAnomalyService;
use App\Services\BehaviorBaselineService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily Behavior Analysis Command
 *
 * Runs every night to:
 * 1. Aggregate daily metrics from attendance and security logs
 * 2. Update behavioral baselines
 * 3. Run anomaly scoring
 * 4. Create alerts for suspicious behavior
 */
class AnalyzeBehaviorDaily extends Command
{
    protected $signature = 'behavior:analyze-daily 
                            {--date= : Specific date to analyze (YYYY-MM-DD), defaults to yesterday}
                            {--school= : Analyze specific school only}
                            {--user= : Analyze specific user only}
                            {--dry-run : Run without saving to database}
                            {--skip-metrics : Skip metrics aggregation, only run analysis}
                            {--skip-baseline : Skip baseline calculation}
                            {--skip-anomaly : Skip anomaly detection}';

    protected $description = 'Aggregate daily behavior metrics, update baselines, and detect anomalies';

    protected BehaviorBaselineService $baselineService;

    protected BehaviorAnomalyService $anomalyService;

    public function __construct(
        BehaviorBaselineService $baselineService,
        BehaviorAnomalyService $anomalyService
    ) {
        parent::__construct();
        $this->baselineService = $baselineService;
        $this->anomalyService = $anomalyService;
    }

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::yesterday();

        $isDryRun = $this->option('dry-run');
        $schoolId = $this->option('school');
        $userId = $this->option('user');

        $this->info("=== Behavior Analysis for {$date->toDateString()} ===");

        if ($isDryRun) {
            $this->warn('DRY RUN - No changes will be saved');
        }

        $stats = [
            'metrics_aggregated' => 0,
            'baselines_updated' => 0,
            'anomalies_detected' => 0,
            'alerts_created' => 0,
            'critical_risks' => 0,
        ];

        // Step 1: Aggregate daily metrics
        if (! $this->option('skip-metrics')) {
            $this->info("\n[Step 1] Aggregating daily metrics...");
            $stats['metrics_aggregated'] = $this->aggregateMetrics($date, $schoolId, $userId, $isDryRun);
            $this->info("  → Aggregated metrics for {$stats['metrics_aggregated']} teachers");
        }

        // Step 2: Update baselines
        if (! $this->option('skip-baseline')) {
            $this->info("\n[Step 2] Updating behavior baselines...");
            $stats['baselines_updated'] = $this->updateBaselines($schoolId, $userId, $isDryRun);
            $this->info("  → Updated {$stats['baselines_updated']} baselines");
        }

        // Step 3: Run anomaly detection
        if (! $this->option('skip-anomaly')) {
            $this->info("\n[Step 3] Running anomaly detection...");
            $anomalyStats = $this->runAnomalyDetection($date, $schoolId, $userId, $isDryRun);
            $stats = array_merge($stats, $anomalyStats);
            $this->info("  → Detected {$stats['anomalies_detected']} anomalies");
            $this->info("  → Created {$stats['alerts_created']} alerts");

            if ($stats['critical_risks'] > 0) {
                $this->error("  → {$stats['critical_risks']} CRITICAL RISKS detected!");
            }
        }

        // Summary
        $this->newLine();
        $this->info('=== Analysis Complete ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Metrics Aggregated', $stats['metrics_aggregated']],
                ['Baselines Updated', $stats['baselines_updated']],
                ['Anomalies Detected', $stats['anomalies_detected']],
                ['Alerts Created', $stats['alerts_created']],
                ['Critical Risks', $stats['critical_risks']],
            ]
        );

        Log::channel('security')->info('Behavior analysis completed', $stats);

        return Command::SUCCESS;
    }

    /**
     * Step 1: Aggregate daily metrics from various sources
     */
    private function aggregateMetrics(Carbon $date, ?int $schoolId, ?int $userId, bool $isDryRun): int
    {
        $dateStr = $date->toDateString();
        $count = 0;

        // Get teachers to analyze
        $teachersQuery = User::where('role_type', 'teacher')
            ->where('is_active', true);

        if ($schoolId) {
            $teachersQuery->where('school_id', $schoolId);
        }
        if ($userId) {
            $teachersQuery->where('id', $userId);
        }

        $teachers = $teachersQuery->get();

        foreach ($teachers as $teacher) {
            $metrics = $this->calculateTeacherMetrics($teacher, $dateStr);

            if ($metrics['total_scans'] === 0) {
                continue; // Skip teachers with no activity
            }

            if (! $isDryRun) {
                BehaviorMetricDaily::updateOrCreate(
                    [
                        'user_id' => $teacher->id,
                        'school_id' => $teacher->school_id,
                        'date' => $dateStr,
                    ],
                    $metrics
                );
            }

            $count++;
            $this->output->write('.');
        }

        $this->newLine();

        return $count;
    }

    /**
     * Calculate metrics for a specific teacher on a specific date
     */
    private function calculateTeacherMetrics(User $teacher, string $date): array
    {
        // Get attendance records scanned by this teacher
        $attendances = Attendance::where('recorded_by', $teacher->id)
            ->whereDate('attendance_date', $date)
            ->orderBy('created_at')
            ->get();

        $totalScans = $attendances->count();
        $successfulScans = $attendances->count(); // All attendance records are successful by definition

        // Get failed attempts from security alerts
        $failedAttempts = SecurityAlert::where('related_user_id', $teacher->id)
            ->whereDate('created_at', $date)
            ->whereIn('type', [
                'qr_replay_attempt',
                'geofence_violation',
                'unauthorized_schedule',
                'unapproved_device',
            ])
            ->get();

        $failedScans = $failedAttempts->count();
        $outsideRadiusAttempts = $failedAttempts->where('type', 'geofence_violation')->count();
        $deviceMismatchAttempts = $failedAttempts->where('type', 'unapproved_device')->count();
        $scheduleMismatchAttempts = $failedAttempts->where('type', 'unauthorized_schedule')->count();
        $qrReplayAttempts = $failedAttempts->where('type', 'qr_replay_attempt')->count();

        // Calculate scan intervals
        $intervals = [];
        $prevTime = null;
        foreach ($attendances as $attendance) {
            if ($prevTime) {
                $intervals[] = $attendance->created_at->diffInSeconds($prevTime);
            }
            $prevTime = $attendance->created_at;
        }

        $avgInterval = count($intervals) > 0 ? array_sum($intervals) / count($intervals) : null;
        $minInterval = count($intervals) > 0 ? min($intervals) : null;
        $maxInterval = count($intervals) > 0 ? max($intervals) : null;

        // Get unique devices used
        $uniqueDevices = $attendances->pluck('device_id_in')->filter()->unique()->count();

        // Get distance metrics from attendance lat/lng
        $distances = [];
        $school = $teacher->school;
        if ($school && $school->latitude && $school->longitude) {
            foreach ($attendances as $attendance) {
                if ($attendance->lat_in && $attendance->lng_in) {
                    $distance = $this->calculateDistance(
                        $attendance->lat_in,
                        $attendance->lng_in,
                        $school->latitude,
                        $school->longitude
                    );
                    $distances[] = $distance;
                }
            }
        }

        $avgDistance = count($distances) > 0 ? array_sum($distances) / count($distances) : null;
        $maxDistance = count($distances) > 0 ? max($distances) : null;

        // Get first and last scan times
        $firstScan = $attendances->first()?->created_at;
        $lastScan = $attendances->last()?->created_at;

        return [
            'total_scans' => $totalScans + $failedScans,
            'successful_scans' => $successfulScans,
            'failed_scans' => $failedScans,
            'outside_radius_attempts' => $outsideRadiusAttempts,
            'device_mismatch_attempts' => $deviceMismatchAttempts,
            'schedule_mismatch_attempts' => $scheduleMismatchAttempts,
            'qr_replay_attempts' => $qrReplayAttempts,
            'avg_scan_interval_seconds' => $avgInterval ? round($avgInterval) : null,
            'min_scan_interval_seconds' => $minInterval,
            'max_scan_interval_seconds' => $maxInterval,
            'avg_distance_from_school' => $avgDistance ? round($avgDistance, 2) : null,
            'max_distance_from_school' => $maxDistance ? round($maxDistance, 2) : null,
            'unique_devices_used' => max(1, $uniqueDevices),
            'first_scan_time' => $firstScan?->format('H:i:s'),
            'last_scan_time' => $lastScan?->format('H:i:s'),
        ];
    }

    /**
     * Step 2: Update behavior baselines
     */
    private function updateBaselines(?int $schoolId, ?int $userId, bool $isDryRun): int
    {
        if ($isDryRun) {
            return 0;
        }

        $count = 0;

        if ($userId) {
            $baseline = $this->baselineService->calculateBaseline($userId);

            return $baseline ? 1 : 0;
        }

        if ($schoolId) {
            $results = $this->baselineService->recalculateSchoolBaselines($schoolId);

            return $results['updated'];
        }

        // All schools
        $results = $this->baselineService->recalculateAllBaselines();

        return $results['updated'];
    }

    /**
     * Step 3: Run anomaly detection
     */
    private function runAnomalyDetection(Carbon $date, ?int $schoolId, ?int $userId, bool $isDryRun): array
    {
        $stats = [
            'anomalies_detected' => 0,
            'alerts_created' => 0,
            'critical_risks' => 0,
        ];

        // Get metrics for the date
        $metricsQuery = BehaviorMetricDaily::forDate($date->toDateString());

        if ($schoolId) {
            $metricsQuery->forSchool($schoolId);
        }
        if ($userId) {
            $metricsQuery->forUser($userId);
        }

        $metrics = $metricsQuery->get();

        foreach ($metrics as $metric) {
            if ($isDryRun) {
                $analysis = $this->anomalyService->analyzeTodayBehavior($metric->user_id);
            } else {
                $analysis = $this->anomalyService->processAndAlert($metric->user_id);
            }

            if (isset($analysis['total_score']) && $analysis['total_score'] >= 3) {
                $stats['anomalies_detected']++;

                if (! $isDryRun) {
                    $stats['alerts_created']++;
                }

                if ($analysis['risk_level'] === 'critical') {
                    $stats['critical_risks']++;
                    $this->warn("  CRITICAL: {$analysis['user_name']} (Score: {$analysis['total_score']})");
                } elseif ($analysis['risk_level'] === 'high') {
                    $this->line("  HIGH RISK: {$analysis['user_name']} (Score: {$analysis['total_score']})");
                }
            }
        }

        return $stats;
    }

    /**
     * Calculate distance between two coordinates (Haversine formula)
     */
    private function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000; // meters

        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lng1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lng2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(
            pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
        ));

        return $angle * $earthRadius;
    }
}
