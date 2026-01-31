<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\SecurityAlert;
use App\Models\TeacherAttendance;
use App\Models\User;
use App\Services\SecurityAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled Anomaly Scan Command
 * 
 * Runs every 5 minutes to detect suspicious patterns:
 * - Same user login from distant locations
 * - High failed scan count in a class
 * - Unusual attendance spikes
 */
class ScanSecurityAnomalies extends Command
{
    protected $signature = 'security:scan-anomalies 
                            {--dry-run : Run without creating alerts}';

    protected $description = 'Scan for security anomalies and create alerts';

    private SecurityAlertService $alertService;

    public function __construct(SecurityAlertService $alertService)
    {
        parent::__construct();
        $this->alertService = $alertService;
    }

    public function handle(): int
    {
        $this->info('Starting security anomaly scan...');
        $isDryRun = $this->option('dry-run');

        $anomaliesFound = 0;

        // 1. Check for impossible travel
        $anomaliesFound += $this->checkImpossibleTravel($isDryRun);

        // 2. Check for failed scan spikes per class
        $anomaliesFound += $this->checkFailedScanSpikes($isDryRun);

        // 3. Check for unusual attendance spikes
        $anomaliesFound += $this->checkAttendanceSpikes($isDryRun);

        // 4. Check for teacher location anomalies
        $anomaliesFound += $this->checkTeacherLocationAnomalies($isDryRun);

        $this->info("Anomaly scan complete. Found: {$anomaliesFound} anomalies.");

        Log::channel('security')->info('Security anomaly scan completed', [
            'anomalies_found' => $anomaliesFound,
            'dry_run' => $isDryRun,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Check 1: Impossible Travel Detection
     * Same user with attendance from 2 far locations within 30 minutes
     */
    private function checkImpossibleTravel(bool $isDryRun): int
    {
        $this->info('  Checking impossible travel patterns...');
        $count = 0;

        // Check teacher attendances in last 30 minutes
        $recentAttendances = TeacherAttendance::where('created_at', '>=', now()->subMinutes(30))
            ->whereNotNull('lat_in')
            ->whereNotNull('lng_in')
            ->orderBy('teacher_id')
            ->orderBy('created_at')
            ->get();

        $teacherLastLocations = [];

        foreach ($recentAttendances as $attendance) {
            $teacherId = $attendance->teacher_id;
            
            if (isset($teacherLastLocations[$teacherId])) {
                $last = $teacherLastLocations[$teacherId];
                $timeDiff = $attendance->created_at->diffInMinutes($last['time']);
                
                if ($timeDiff <= 30 && $timeDiff > 0) {
                    $distance = $this->calculateDistance(
                        $last['lat'], $last['lng'],
                        $attendance->lat_in, $attendance->lng_in
                    );
                    
                    // If more than 5km in 30 minutes → suspicious
                    if ($distance > 5000) {
                        $count++;
                        $this->warn("    Found impossible travel: Teacher {$teacherId}, {$distance}m in {$timeDiff} min");
                        
                        if (!$isDryRun) {
                            $teacher = User::find($teacherId);
                            if ($teacher) {
                                $this->alertService->alertImpossibleTravel(
                                    $teacher,
                                    $teacher->school_id,
                                    $distance,
                                    $timeDiff,
                                    [
                                        'from' => ['lat' => $last['lat'], 'lng' => $last['lng'], 'time' => $last['time']->toIso8601String()],
                                        'to' => ['lat' => $attendance->lat_in, 'lng' => $attendance->lng_in, 'time' => $attendance->created_at->toIso8601String()],
                                    ]
                                );
                            }
                        }
                    }
                }
            }
            
            $teacherLastLocations[$teacherId] = [
                'lat' => $attendance->lat_in,
                'lng' => $attendance->lng_in,
                'time' => $attendance->created_at,
            ];
        }

        return $count;
    }

    /**
     * Check 2: Failed Scan Spikes
     * More than 10 failed/blocked scans in a class within last 15 minutes
     */
    private function checkFailedScanSpikes(bool $isDryRun): int
    {
        $this->info('  Checking failed scan spikes...');
        $count = 0;

        // Query security alerts for failed scans grouped by school
        $failedScans = SecurityAlert::query()
            ->select('school_id', DB::raw('COUNT(*) as fail_count'))
            ->where('created_at', '>=', now()->subMinutes(15))
            ->whereIn('type', [
                SecurityAlert::TYPE_QR_REPLAY,
                SecurityAlert::TYPE_RACE_CONDITION_BLOCKED,
                SecurityAlert::TYPE_GEOFENCE_VIOLATION,
                SecurityAlert::TYPE_UNAUTHORIZED_SCHEDULE,
            ])
            ->whereNotNull('school_id')
            ->groupBy('school_id')
            ->havingRaw('COUNT(*) > 10')
            ->get();

        foreach ($failedScans as $spike) {
            $count++;
            $this->warn("    Found failed scan spike: School {$spike->school_id}, {$spike->fail_count} failures");
            
            if (!$isDryRun) {
                $this->alertService->alertFailedAttemptSpike(
                    null,
                    $spike->school_id,
                    $spike->fail_count,
                    'High number of failed/blocked attendance scans detected'
                );
            }
        }

        return $count;
    }

    /**
     * Check 3: Attendance Spikes
     * Schedule with significantly higher attendance than normal
     */
    private function checkAttendanceSpikes(bool $isDryRun): int
    {
        $this->info('  Checking attendance spikes...');
        $count = 0;

        // Get today's attendance per schedule
        $todayAttendance = Attendance::whereDate('attendance_date', today())
            ->select('school_id', 'schedule_id', DB::raw('COUNT(*) as today_count'))
            ->groupBy('school_id', 'schedule_id')
            ->get();

        foreach ($todayAttendance as $record) {
            // Calculate average for this schedule over last 7 days
            $avgAttendance = Attendance::where('schedule_id', $record->schedule_id)
                ->where('attendance_date', '>=', now()->subDays(7))
                ->where('attendance_date', '<', today())
                ->count() / 7;

            // If today's count is more than 2x the average → spike
            if ($avgAttendance > 0 && $record->today_count > ($avgAttendance * 2)) {
                $count++;
                $this->warn("    Found attendance spike: Schedule {$record->schedule_id}, {$record->today_count} (avg: " . round($avgAttendance) . ")");
                
                if (!$isDryRun) {
                    $this->alertService->alertAttendanceSpike(
                        $record->school_id,
                        $record->schedule_id, // Using schedule_id instead of class_id
                        $record->today_count,
                        (int) round($avgAttendance)
                    );
                }
            }
        }

        return $count;
    }

    /**
     * Check 4: Teacher Location Anomalies
     * Teachers checking in from locations far from school
     */
    private function checkTeacherLocationAnomalies(bool $isDryRun): int
    {
        $this->info('  Checking teacher location anomalies...');
        $count = 0;

        // Get recent teacher check-ins with location
        $recentCheckins = TeacherAttendance::where('created_at', '>=', now()->subMinutes(30))
            ->whereNotNull('lat_in')
            ->whereNotNull('lng_in')
            ->where('distance_in', '>', 100) // More than 100m from school
            ->with(['teacher', 'teacher.school'])
            ->get();

        foreach ($recentCheckins as $checkin) {
            if ($checkin->distance_in > 200) { // Flag if > 200m
                $count++;
                $this->warn("    Found location anomaly: Teacher {$checkin->teacher_id}, {$checkin->distance_in}m from school");
                
                if (!$isDryRun && $checkin->teacher) {
                    // This is already handled by real-time check, but log for pattern analysis
                    Log::channel('security')->info('Teacher location anomaly detected in scan', [
                        'teacher_id' => $checkin->teacher_id,
                        'distance' => $checkin->distance_in,
                        'school_id' => $checkin->school_id,
                    ]);
                }
            }
        }

        return $count;
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
