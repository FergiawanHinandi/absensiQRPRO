<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\School;
use App\Models\TeacherAttendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Teacher Location Heatmap Service
 * 
 * Provides clustering and anomaly detection for scan location visualization.
 */
class TeacherHeatmapService
{
    /**
     * Cluster radius in meters for grouping nearby points
     */
    private const CLUSTER_RADIUS_METERS = 10;

    /**
     * Maximum allowed distance from school center in meters
     */
    private const ALLOWED_ZONE_RADIUS = 50;

    /**
     * Get clustered heatmap data for a school
     */
    public function getHeatmapData(
        int $schoolId,
        ?int $teacherId = null,
        ?string $date = null,
        ?string $range = null
    ): array {
        $school = School::find($schoolId);
        if (!$school) {
            return ['points' => [], 'school' => null];
        }

        // Determine date range
        [$startDate, $endDate] = $this->getDateRange($date, $range);

        // Query scan locations from attendance_logs
        $logsQuery = AttendanceLog::query()
            ->forSchool($schoolId)
            ->withLocation()
            ->scanActions()
            ->whereBetween('created_at', [$startDate, $endDate]);

        if ($teacherId) {
            $logsQuery->forTeacher($teacherId);
        }

        $logs = $logsQuery->get();

        // Also get teacher_attendances for check-in/out locations
        $teacherAttendancesQuery = TeacherAttendance::query()
            ->where('school_id', $schoolId)
            ->whereBetween('attendance_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->where(function ($q) {
                $q->whereNotNull('lat_in')
                  ->orWhereNotNull('lat_out');
            });

        if ($teacherId) {
            $teacherAttendancesQuery->where('teacher_id', $teacherId);
        }

        $teacherAttendances = $teacherAttendancesQuery->get();

        // Combine all location points
        $points = $this->combineLocationPoints($logs, $teacherAttendances);

        // Cluster points
        $clusters = $this->clusterPoints($points);

        // Add anomaly flags
        $clusters = $this->flagAnomalies($clusters, $school);

        return [
            'points' => $clusters,
            'school' => [
                'id' => $school->id,
                'name' => $school->name,
                'latitude' => (float) $school->latitude,
                'longitude' => (float) $school->longitude,
                'radius_meters' => $school->radius_meters ?? self::ALLOWED_ZONE_RADIUS,
            ],
            'date_range' => [
                'start' => $startDate->toIso8601String(),
                'end' => $endDate->toIso8601String(),
            ],
            'stats' => [
                'total_points' => $points->count(),
                'total_clusters' => count($clusters),
                'outside_zone_count' => collect($clusters)->where('outside_zone', true)->count(),
            ],
        ];
    }

    /**
     * Get detailed scan information for a specific location cluster
     */
    public function getClusterDetails(
        int $schoolId,
        float $lat,
        float $lng,
        ?string $date = null,
        ?string $range = null
    ): array {
        [$startDate, $endDate] = $this->getDateRange($date, $range);

        // Get all logs within cluster radius
        $logs = AttendanceLog::query()
            ->forSchool($schoolId)
            ->withLocation()
            ->scanActions()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get()
            ->filter(function ($log) use ($lat, $lng) {
                return $this->calculateDistance(
                    $log->latitude, $log->longitude, $lat, $lng
                ) <= self::CLUSTER_RADIUS_METERS;
            });

        // Group by teacher
        $byTeacher = $logs->groupBy('teacher_id');

        $details = [];
        foreach ($byTeacher as $teacherId => $teacherLogs) {
            $teacher = User::find($teacherId);
            $details[] = [
                'teacher_id' => $teacherId,
                'teacher_name' => $teacher?->name ?? 'Unknown',
                'scan_count' => $teacherLogs->count(),
                'students_scanned' => $teacherLogs->sum('students_scanned'),
                'times' => $teacherLogs->pluck('created_at')->map(fn($t) => $t->format('H:i:s'))->toArray(),
                'first_scan' => $teacherLogs->min('created_at')?->format('Y-m-d H:i:s'),
                'last_scan' => $teacherLogs->max('created_at')?->format('Y-m-d H:i:s'),
            ];
        }

        return [
            'location' => ['lat' => $lat, 'lng' => $lng],
            'teachers' => $details,
            'total_scans' => $logs->count(),
            'total_students' => $logs->sum('students_scanned'),
        ];
    }

    /**
     * Get teacher scan summary for a specific period
     */
    public function getTeacherScanSummary(
        int $schoolId,
        ?string $date = null,
        ?string $range = null
    ): array {
        [$startDate, $endDate] = $this->getDateRange($date, $range);

        $school = School::find($schoolId);
        $schoolLat = $school?->latitude ?? 0;
        $schoolLng = $school?->longitude ?? 0;
        $allowedRadius = $school?->radius_meters ?? self::ALLOWED_ZONE_RADIUS;

        // Aggregate by teacher
        $summary = AttendanceLog::query()
            ->forSchool($schoolId)
            ->withLocation()
            ->scanActions()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->select([
                'teacher_id',
                DB::raw('COUNT(*) as total_scans'),
                DB::raw('SUM(students_scanned) as total_students'),
                DB::raw('MIN(created_at) as first_scan'),
                DB::raw('MAX(created_at) as last_scan'),
                DB::raw('AVG(latitude) as avg_lat'),
                DB::raw('AVG(longitude) as avg_lng'),
            ])
            ->groupBy('teacher_id')
            ->get();

        $result = [];
        foreach ($summary as $row) {
            $teacher = User::find($row->teacher_id);
            $avgDistance = $this->calculateDistance(
                $row->avg_lat, $row->avg_lng, $schoolLat, $schoolLng
            );

            $result[] = [
                'teacher_id' => $row->teacher_id,
                'teacher_name' => $teacher?->name ?? 'Unknown',
                'total_scans' => (int) $row->total_scans,
                'total_students' => (int) $row->total_students,
                'first_scan' => $row->first_scan,
                'last_scan' => $row->last_scan,
                'avg_distance_from_school' => round($avgDistance),
                'has_outside_zone_scans' => $avgDistance > $allowedRadius,
            ];
        }

        return $result;
    }

    /**
     * Combine location points from multiple sources
     */
    private function combineLocationPoints(Collection $logs, Collection $teacherAttendances): Collection
    {
        $points = collect();

        // Add attendance logs
        foreach ($logs as $log) {
            $points->push([
                'lat' => (float) $log->latitude,
                'lng' => (float) $log->longitude,
                'teacher_id' => $log->teacher_id,
                'students_scanned' => $log->students_scanned ?? 1,
                'time' => $log->created_at,
                'source' => 'attendance_log',
            ]);
        }

        // Add teacher attendances (check-in)
        foreach ($teacherAttendances as $ta) {
            if ($ta->lat_in && $ta->lng_in) {
                $points->push([
                    'lat' => (float) $ta->lat_in,
                    'lng' => (float) $ta->lng_in,
                    'teacher_id' => $ta->teacher_id,
                    'students_scanned' => 0, // This is teacher's own check-in
                    'time' => $ta->check_in_time,
                    'source' => 'teacher_checkin',
                ]);
            }
            if ($ta->lat_out && $ta->lng_out) {
                $points->push([
                    'lat' => (float) $ta->lat_out,
                    'lng' => (float) $ta->lng_out,
                    'teacher_id' => $ta->teacher_id,
                    'students_scanned' => 0,
                    'time' => $ta->check_out_time,
                    'source' => 'teacher_checkout',
                ]);
            }
        }

        return $points;
    }

    /**
     * Cluster nearby points within radius
     */
    private function clusterPoints(Collection $points): array
    {
        if ($points->isEmpty()) {
            return [];
        }

        $clusters = [];
        $assigned = [];

        foreach ($points as $index => $point) {
            if (isset($assigned[$index])) {
                continue;
            }

            // Start a new cluster
            $cluster = [
                'lat' => $point['lat'],
                'lng' => $point['lng'],
                'count' => 1,
                'students_scanned' => $point['students_scanned'],
                'teacher_ids' => [$point['teacher_id']],
                'times' => [$point['time']],
                'sources' => [$point['source']],
            ];
            $assigned[$index] = true;

            // Find nearby points
            foreach ($points as $i => $other) {
                if (isset($assigned[$i]) || $i === $index) {
                    continue;
                }

                $distance = $this->calculateDistance(
                    $point['lat'], $point['lng'],
                    $other['lat'], $other['lng']
                );

                if ($distance <= self::CLUSTER_RADIUS_METERS) {
                    // Add to cluster (weighted average for center)
                    $totalCount = $cluster['count'] + 1;
                    $cluster['lat'] = ($cluster['lat'] * $cluster['count'] + $other['lat']) / $totalCount;
                    $cluster['lng'] = ($cluster['lng'] * $cluster['count'] + $other['lng']) / $totalCount;
                    $cluster['count'] = $totalCount;
                    $cluster['students_scanned'] += $other['students_scanned'];
                    $cluster['teacher_ids'][] = $other['teacher_id'];
                    $cluster['times'][] = $other['time'];
                    $cluster['sources'][] = $other['source'];
                    $assigned[$i] = true;
                }
            }

            // Finalize cluster
            $cluster['teacher_ids'] = array_values(array_unique($cluster['teacher_ids']));
            $cluster['teacher_count'] = count($cluster['teacher_ids']);
            $cluster['first_time'] = collect($cluster['times'])->min()?->format('Y-m-d H:i:s');
            $cluster['last_time'] = collect($cluster['times'])->max()?->format('Y-m-d H:i:s');
            unset($cluster['times'], $cluster['sources']);

            $clusters[] = $cluster;
        }

        // Sort by count descending
        usort($clusters, fn($a, $b) => $b['count'] <=> $a['count']);

        return $clusters;
    }

    /**
     * Flag clusters that are outside allowed zone
     */
    private function flagAnomalies(array $clusters, School $school): array
    {
        $schoolLat = (float) $school->latitude;
        $schoolLng = (float) $school->longitude;
        $allowedRadius = $school->radius_meters ?? self::ALLOWED_ZONE_RADIUS;

        foreach ($clusters as &$cluster) {
            $distance = $this->calculateDistance(
                $cluster['lat'], $cluster['lng'],
                $schoolLat, $schoolLng
            );

            $cluster['distance_from_school'] = round($distance);
            $cluster['outside_zone'] = $distance > $allowedRadius;
        }

        return $clusters;
    }

    /**
     * Get date range based on parameters
     */
    private function getDateRange(?string $date, ?string $range): array
    {
        if ($date) {
            $dateObj = Carbon::parse($date);
            return [$dateObj->startOfDay(), $dateObj->endOfDay()];
        }

        $end = Carbon::now()->endOfDay();
        $days = match ($range) {
            '30d' => 30,
            '14d' => 14,
            default => 7,
        };

        return [Carbon::now()->subDays($days)->startOfDay(), $end];
    }

    /**
     * Calculate distance between two coordinates in meters (Haversine formula)
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
