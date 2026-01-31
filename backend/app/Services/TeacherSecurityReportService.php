<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\BehaviorBaseline;
use App\Models\BehaviorMetricDaily;
use App\Models\School;
use App\Models\SecurityAlert;
use App\Models\SecurityReport;
use App\Models\TeacherAttendance;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Teacher Security Report Service
 * 
 * Generates comprehensive investigation reports for suspicious teacher behavior.
 */
class TeacherSecurityReportService
{
    protected BehaviorAnomalyService $behaviorService;
    protected TeacherHeatmapService $heatmapService;

    public function __construct(
        BehaviorAnomalyService $behaviorService,
        TeacherHeatmapService $heatmapService
    ) {
        $this->behaviorService = $behaviorService;
        $this->heatmapService = $heatmapService;
    }

    /**
     * Generate a security investigation report for a teacher
     */
    public function generateReport(
        int $teacherId,
        string $range = '7d',
        int $generatedBy,
        string $generationType = SecurityReport::GENERATION_MANUAL
    ): SecurityReport {
        $teacher = User::with(['school', 'deviceBindings'])->findOrFail($teacherId);
        $school = $teacher->school;

        // Get date range
        [$startDate, $endDate] = $this->getDateRange($range);

        // Collect all evidence data
        $reportData = $this->collectEvidence($teacher, $school, $startDate, $endDate);

        // Determine risk level
        $riskLevel = $this->determineRiskLevel($reportData);

        // Generate PDF
        $pdf = $this->generatePdf($reportData, $teacher, $school, $startDate, $endDate, $riskLevel);

        // Store the PDF
        $fileName = $this->generateFileName($teacher, $startDate);
        $filePath = $this->storePdf($pdf, $fileName);

        // Create database record
        $report = SecurityReport::create([
            'teacher_id' => $teacherId,
            'school_id' => $school->id,
            'generated_by' => $generatedBy,
            'file_path' => $filePath,
            'file_name' => $fileName,
            'risk_level' => $riskLevel,
            'date_range' => $range,
            'summary_data' => $this->getSummaryData($reportData, $riskLevel),
            'generation_type' => $generationType,
            'report_period_start' => $startDate,
            'report_period_end' => $endDate,
        ]);

        Log::channel('security')->info('Security report generated', [
            'report_id' => $report->id,
            'teacher_id' => $teacherId,
            'risk_level' => $riskLevel,
            'generated_by' => $generatedBy,
            'generation_type' => $generationType,
        ]);

        return $report;
    }

    /**
     * Collect all evidence data for the report
     */
    protected function collectEvidence(User $teacher, School $school, Carbon $startDate, Carbon $endDate): array
    {
        return [
            'teacher_profile' => $this->getTeacherProfile($teacher),
            'attendance_summary' => $this->getAttendanceSummary($teacher->id, $school->id, $startDate, $endDate),
            'behavior_analysis' => $this->getBehaviorAnalysis($teacher->id, $startDate, $endDate),
            'location_analysis' => $this->getLocationAnalysis($school->id, $teacher->id, $startDate, $endDate),
            'device_history' => $this->getDeviceHistory($teacher->id, $startDate, $endDate),
            // 'security_alerts' => $this->getSecurityAlerts($teacher->id, $startDate, $endDate), // Uncomment if needed
        ];
    }

    /**
     * Section 1: Get teacher profile information
     */
    protected function getTeacherProfile(User $teacher): array
    {
        $latestBinding = $teacher->deviceBindings()
            ->where('is_active', true)
            ->latest()
            ->first();

        $lastLogin = AttendanceLog::where('teacher_id', $teacher->id)
            ->whereIn('action', ['scan', 'check_in'])
            ->latest()
            ->first();

        return [
            'id' => $teacher->id,
            'name' => $teacher->name,
            'email' => $teacher->email,
            'school_name' => $teacher->school->name ?? 'N/A',
            'school_id' => $teacher->school_id,
            'role' => $teacher->role_type,
            'created_at' => $teacher->created_at->format('Y-m-d'),
            'bound_device_id' => $latestBinding?->device_id ?? 'No device bound',
            'device_bound_at' => $latestBinding?->created_at?->format('Y-m-d H:i:s') ?? 'N/A',
            'last_login_device' => $lastLogin?->device_id ?? 'N/A',
            'last_login_at' => $lastLogin?->created_at?->format('Y-m-d H:i:s') ?? 'N/A',
            'is_active' => $teacher->is_active,
        ];
    }

    /**
     * Section 2: Get attendance summary
     */
    protected function getAttendanceSummary(int $teacherId, int $schoolId, Carbon $startDate, Carbon $endDate): array
    {
        // Total student scans performed by this teacher
        $scans = Attendance::where('school_id', $schoolId)
            ->where('recorded_by', $teacherId)
            ->whereBetween('attendance_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get();

        $totalScans = $scans->count();

        // Failed scans from attendance_logs
        $failedScans = AttendanceLog::forSchool($schoolId)
            ->forTeacher($teacherId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('success', false)
            ->count();

        // Outside radius attempts
        $outsideRadiusAttempts = AttendanceLog::forSchool($schoolId)
            ->forTeacher($teacherId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where(function ($query) {
                $query->whereJsonContains('details->outside_radius', true)
                    ->orWhere('action', 'outside_radius_attempt');
            })
            ->count();

        // Schedule violations
        $scheduleViolations = AttendanceLog::forSchool($schoolId)
            ->forTeacher($teacherId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where(function ($query) {
                $query->whereJsonContains('details->schedule_mismatch', true)
                    ->orWhere('action', 'schedule_violation');
            })
            ->count();

        // Teacher own attendance
        $teacherAttendance = TeacherAttendance::where('teacher_id', $teacherId)
            ->where('school_id', $schoolId)
            ->whereBetween('attendance_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get();

        return [
            'total_student_scans' => $totalScans,
            'failed_scans' => $failedScans,
            'success_rate' => $totalScans > 0 ? round((($totalScans - $failedScans) / $totalScans) * 100, 1) : 0,
            'outside_radius_attempts' => $outsideRadiusAttempts,
            'schedule_violations' => $scheduleViolations,
            'teacher_present_days' => $teacherAttendance->where('status', 'present')->count(),
            'teacher_absent_days' => $teacherAttendance->whereIn('status', ['absent', 'alpha'])->count(),
            'teacher_late_days' => $teacherAttendance->where('status', 'late')->count(),
        ];
    }

    /**
     * Section 3: Get behavior anomaly analysis
     */
    protected function getBehaviorAnalysis(int $teacherId, Carbon $startDate, Carbon $endDate): array
    {
        // Get daily metrics for the period
        $dailyMetrics = BehaviorMetricDaily::forUser($teacherId)
            ->whereBetween('metric_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->orderBy('metric_date')
            ->get();

        // Get baseline
        $baseline = BehaviorBaseline::where('user_id', $teacherId)->first();

        // Get today's analysis if available
        $todayAnalysis = $this->behaviorService->analyzeTodayBehavior($teacherId);

        // Calculate period statistics
        $periodStats = [
            'avg_daily_scans' => round($dailyMetrics->avg('total_scans'), 1),
            'max_daily_scans' => $dailyMetrics->max('total_scans'),
            'total_failed_scans' => $dailyMetrics->sum('failed_scans'),
            'avg_failed_ratio' => round($dailyMetrics->avg('failed_ratio') * 100, 1),
            'total_outside_radius' => $dailyMetrics->sum('outside_radius_attempts'),
            'total_device_mismatch' => $dailyMetrics->sum('device_mismatch_attempts'),
            'total_schedule_mismatch' => $dailyMetrics->sum('schedule_mismatch_attempts'),
            'total_qr_replay' => $dailyMetrics->sum('qr_replay_attempts'),
            'days_with_rapid_scanning' => $dailyMetrics->where('avg_scan_interval_seconds', '<', 5)->count(),
        ];

        // Get risk trend
        $riskTrend = $dailyMetrics->map(function ($metric) {
            return [
                'date' => $metric->metric_date->format('Y-m-d'),
                'score' => $metric->risk_score ?? 0,
                'risk_level' => $metric->risk_level ?? 'low',
            ];
        })->toArray();

        return [
            'current_risk_level' => $todayAnalysis['risk_level'] ?? 'unknown',
            'current_risk_score' => $todayAnalysis['total_score'] ?? 0,
            'triggered_flags' => $todayAnalysis['triggered_flags'] ?? [],
            'score_breakdown' => $todayAnalysis['score_breakdown'] ?? [],
            'period_stats' => $periodStats,
            'risk_trend' => $riskTrend,
            'baseline' => $baseline ? [
                'avg_scans_per_day' => $baseline->avg_scans_per_day,
                'avg_failed_ratio' => round($baseline->avg_failed_ratio * 100, 1),
                'days_in_baseline' => $baseline->days_in_baseline,
            ] : null,
        ];
    }

    /**
     * Section 4: Get security alerts history
     */
    protected function getSecurityAlerts(int $teacherId, Carbon $startDate, Carbon $endDate): array
    {
        $alerts = SecurityAlert::where('related_user_id', $teacherId)
            ->whereBetween('detected_at', [$startDate, $endDate])
            ->orderBy('detected_at', 'desc')
            ->get();

        $alertsByType = $alerts->groupBy('type')->map->count();
        $alertsBySeverity = $alerts->groupBy('severity')->map->count();

        return [
            'total_alerts' => $alerts->count(),
            'critical_alerts' => $alerts->where('severity', 'critical')->count(),
            'high_alerts' => $alerts->where('severity', 'high')->count(),
            'medium_alerts' => $alerts->where('severity', 'medium')->count(),
            'low_alerts' => $alerts->where('severity', 'low')->count(),
            'unresolved_alerts' => $alerts->where('is_resolved', false)->count(),
            'alerts_by_type' => $alertsByType->toArray(),
            'alerts_by_severity' => $alertsBySeverity->toArray(),
            'timeline' => $alerts->map(function ($alert) {
                return [
                    'id' => $alert->id,
                    'type' => $alert->type,
                    'severity' => $alert->severity,
                    'description' => $alert->description,
                    'timestamp' => $alert->detected_at->format('Y-m-d H:i:s'),
                    'is_resolved' => $alert->is_resolved,
                    'ip_address' => $alert->ip_address,
                    'device_id' => $alert->device_id,
                ];
            })->toArray(),
        ];
    }

    /**
     * Section 5: Get location analysis
     */
    protected function getLocationAnalysis(int $schoolId, int $teacherId, Carbon $startDate, Carbon $endDate): array
    {
        $range = $startDate->diffInDays($endDate) . 'd';
        $heatmapData = $this->heatmapService->getHeatmapData(
            $schoolId,
            $teacherId,
            null,
            $range
        );

        $points = collect($heatmapData['points'] ?? []);
        $school = $heatmapData['school'] ?? null;

        $insideZoneClusters = $points->where('outside_zone', false);
        $outsideZoneClusters = $points->where('outside_zone', true);

        return [
            'total_scan_clusters' => $points->count(),
            'clusters_inside_school' => $insideZoneClusters->count(),
            'clusters_outside_school' => $outsideZoneClusters->count(),
            'total_scans_inside' => $insideZoneClusters->sum('count'),
            'total_scans_outside' => $outsideZoneClusters->sum('count'),
            'school_radius_meters' => $school['radius_meters'] ?? 50,
            'outside_zone_details' => $outsideZoneClusters->map(function ($cluster) {
                return [
                    'lat' => $cluster['lat'],
                    'lng' => $cluster['lng'],
                    'scan_count' => $cluster['count'],
                    'distance_from_school' => round($cluster['distance_from_school'], 1),
                    'students_affected' => $cluster['students_scanned'] ?? 0,
                ];
            })->values()->toArray(),
        ];
    }

    /**
     * Section 6: Get device history
     */
    protected function getDeviceHistory(int $teacherId, Carbon $startDate, Carbon $endDate): array
    {
        // Get all devices used during period
        $devices = AttendanceLog::forTeacher($teacherId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('device_id')
            ->select('device_id')
            ->selectRaw('COUNT(*) as usage_count')
            ->selectRaw('MIN(created_at) as first_used')
            ->selectRaw('MAX(created_at) as last_used')
            ->groupBy('device_id')
            ->get();

        // Get bound device
        $teacher = User::with(['deviceBindings' => function ($q) {
            $q->where('is_active', true)->latest();
        }])->find($teacherId);

        $boundDeviceId = $teacher?->deviceBindings?->first()?->device_id;

        // Check for device mismatches
        $mismatches = [];
        if ($boundDeviceId) {
            $mismatches = $devices->filter(function ($device) use ($boundDeviceId) {
                return $device->device_id !== $boundDeviceId;
            })->map(function ($device) {
                return [
                    'device_id' => $device->device_id,
                    'usage_count' => $device->usage_count,
                    'first_used' => Carbon::parse($device->first_used)->format('Y-m-d H:i:s'),
                    'last_used' => Carbon::parse($device->last_used)->format('Y-m-d H:i:s'),
                ];
            })->values()->toArray();
        }

        return [
            'bound_device' => $boundDeviceId,
            'total_devices_used' => $devices->count(),
            'devices' => $devices->map(function ($device) use ($boundDeviceId) {
                return [
                    'device_id' => $device->device_id,
                    'is_bound' => $device->device_id === $boundDeviceId,
                    'usage_count' => $device->usage_count,
                    'first_used' => Carbon::parse($device->first_used)->format('Y-m-d H:i:s'),
                    'last_used' => Carbon::parse($device->last_used)->format('Y-m-d H:i:s'),
                ];
            })->toArray(),
            'mismatch_count' => count($mismatches),
            'mismatched_devices' => $mismatches,
            'device_integrity' => count($mismatches) === 0 ? 'PASSED' : 'FAILED',
        ];
    }

    /**
     * Determine overall risk level based on evidence
     */
    protected function determineRiskLevel(array $reportData): string
    {
        $score = 0;

        // Behavior analysis scoring
        $behavior = $reportData['behavior_analysis'];
        $score += $behavior['current_risk_score'] ?? 0;

        // Security alerts scoring
        $alerts = $reportData['security_alerts'];
        $score += $alerts['critical_alerts'] * 5;
        $score += $alerts['high_alerts'] * 3;
        $score += $alerts['medium_alerts'] * 1;

        // Location abuse scoring
        $location = $reportData['location_analysis'];
        if ($location['clusters_outside_school'] > 0) {
            $score += $location['clusters_outside_school'] * 3;
        }

        // Device integrity scoring
        $device = $reportData['device_history'];
        if ($device['device_integrity'] === 'FAILED') {
            $score += $device['mismatch_count'] * 4;
        }

        // Attendance issues
        $attendance = $reportData['attendance_summary'];
        if ($attendance['failed_scans'] > 10) {
            $score += 2;
        }
        if ($attendance['outside_radius_attempts'] > 5) {
            $score += 3;
        }

        // Map score to risk level
        if ($score >= 15) {
            return SecurityReport::RISK_CRITICAL;
        } elseif ($score >= 10) {
            return SecurityReport::RISK_HIGH;
        } elseif ($score >= 5) {
            return SecurityReport::RISK_MEDIUM;
        }

        return SecurityReport::RISK_LOW;
    }

    /**
     * Generate the PDF document
     */
    protected function generatePdf(
        array $reportData,
        User $teacher,
        School $school,
        Carbon $startDate,
        Carbon $endDate,
        string $riskLevel
    ) {
        $conclusion = $this->generateConclusion($riskLevel, $reportData);

        $data = [
            'report_id' => Str::uuid()->toString(),
            'generated_at' => now()->format('Y-m-d H:i:s'),
            'period_start' => $startDate->format('Y-m-d'),
            'period_end' => $endDate->format('Y-m-d'),
            'teacher' => $reportData['teacher_profile'],
            'school' => [
                'name' => $school->name,
                'address' => $school->address ?? 'N/A',
            ],
            'attendance' => $reportData['attendance_summary'],
            'behavior' => $reportData['behavior_analysis'],
            'alerts' => $reportData['security_alerts'],
            'location' => $reportData['location_analysis'],
            'device' => $reportData['device_history'],
            'risk_level' => $riskLevel,
            'risk_color' => $this->getRiskColor($riskLevel),
            'conclusion' => $conclusion,
        ];

        return Pdf::loadView('reports.teacher-security-investigation', $data)
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'defaultFont' => 'sans-serif',
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => false,
            ]);
    }

    /**
     * Generate conclusion based on risk level and evidence
     */
    protected function generateConclusion(string $riskLevel, array $reportData): array
    {
        $summary = match ($riskLevel) {
            SecurityReport::RISK_CRITICAL => 'Strong indication of attendance misuse detected. Immediate administrative review required.',
            SecurityReport::RISK_HIGH => 'Suspicious pattern requires school review. Multiple security flags triggered.',
            SecurityReport::RISK_MEDIUM => 'Minor anomaly detected. Continued monitoring recommended.',
            SecurityReport::RISK_LOW => 'No significant security concerns detected. Normal attendance behavior.',
            default => 'Unable to determine risk level.',
        };

        $recommendations = [];

        if ($riskLevel === SecurityReport::RISK_CRITICAL || $riskLevel === SecurityReport::RISK_HIGH) {
            $recommendations[] = 'Schedule a meeting with the teacher to discuss findings';
            
            if ($reportData['device_history']['device_integrity'] === 'FAILED') {
                $recommendations[] = 'Verify device binding and consider re-binding';
            }
            
            if ($reportData['location_analysis']['clusters_outside_school'] > 0) {
                $recommendations[] = 'Review location scan policies and provide clarification';
            }
            
            if ($reportData['security_alerts']['unresolved_alerts'] > 0) {
                $recommendations[] = 'Resolve pending security alerts';
            }
        }

        if ($riskLevel === SecurityReport::RISK_MEDIUM) {
            $recommendations[] = 'Continue monitoring for the next 7 days';
            $recommendations[] = 'Review attendance logs weekly';
        }

        if ($riskLevel === SecurityReport::RISK_LOW) {
            $recommendations[] = 'No action required';
            $recommendations[] = 'Maintain standard monitoring protocols';
        }

        return [
            'risk_level' => strtoupper($riskLevel),
            'summary' => $summary,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Store the PDF file
     */
    protected function storePdf($pdf, string $fileName): string
    {
        $directory = 'security-reports';
        
        // Ensure directory exists
        if (!Storage::exists($directory)) {
            Storage::makeDirectory($directory);
        }

        $path = $directory . '/' . $fileName;
        Storage::put($path, $pdf->output());

        return $path;
    }

    /**
     * Generate a unique filename
     */
    protected function generateFileName(User $teacher, Carbon $startDate): string
    {
        $timestamp = now()->format('Ymd_His');
        $teacherSlug = Str::slug($teacher->name, '_');
        
        return "investigation_{$teacherSlug}_{$startDate->format('Ymd')}_{$timestamp}.pdf";
    }

    /**
     * Get date range from range string
     */
    protected function getDateRange(string $range): array
    {
        $endDate = now();
        
        $days = match ($range) {
            '7d' => 7,
            '14d' => 14,
            '30d' => 30,
            default => 7,
        };

        $startDate = now()->subDays($days)->startOfDay();

        return [$startDate, $endDate];
    }

    /**
     * Get summary data for database storage
     */
    protected function getSummaryData(array $reportData, string $riskLevel): array
    {
        return [
            'risk_level' => $riskLevel,
            'total_alerts' => $reportData['security_alerts']['total_alerts'],
            'critical_alerts' => $reportData['security_alerts']['critical_alerts'],
            'outside_zone_clusters' => $reportData['location_analysis']['clusters_outside_school'],
            'device_integrity' => $reportData['device_history']['device_integrity'],
            'triggered_flags' => $reportData['behavior_analysis']['triggered_flags'] ?? [],
        ];
    }

    /**
     * Get risk level color
     */
    protected function getRiskColor(string $riskLevel): string
    {
        return match ($riskLevel) {
            SecurityReport::RISK_CRITICAL => '#dc2626',
            SecurityReport::RISK_HIGH => '#ea580c',
            SecurityReport::RISK_MEDIUM => '#ca8a04',
            SecurityReport::RISK_LOW => '#16a34a',
            default => '#6b7280',
        };
    }
}
