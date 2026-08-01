<?php

namespace App\Services;

use App\Exceptions\AttendanceException;
use App\Models\School;
use App\Services\Logging\AttendanceLogger;
use Illuminate\Http\Request;

/**
 * AttendanceGeofenceService
 *
 * Validates that a check-in attempt occurs within the school's geofence radius.
 * Uses the Haversine formula for accurate distance calculation in meters.
 *
 * Extracted from AttendanceCheckInService to isolate geospatial concerns.
 */
class AttendanceGeofenceService
{
    public function __construct(
        private AttendanceLogger $logger
    ) {}

    /**
     * Validate that the given coordinates are within the school's geofence.
     *
     * Skips validation silently when:
     * - School has no configured lat/lng (geofence not set up)
     * - Coordinates are not provided (optional in some flows)
     *
     * @throws AttendanceException When coordinates are outside the allowed radius
     */
    public function validate(
        ?School $school,
        ?float $lat,
        ?float $lng,
        ?Request $request = null
    ): void {
        if (! $school || ! $school->latitude || ! $school->longitude) {
            return;
        }

        if (! $lat || ! $lng) {
            return;
        }

        $distance = $this->calculateDistance($lat, $lng, $school->latitude, $school->longitude);
        $maxRadius = $school->radius_meters ?? 100;

        if ($distance > $maxRadius) {
            if ($request) {
                $this->logger->securityAnomaly(
                    $request,
                    'outside_geofence',
                    'Attendance attempt from outside allowed radius',
                    [
                        'distance_meters' => round($distance, 2),
                        'max_radius' => $maxRadius,
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'severity' => 'medium',
                    ]
                );
            }
            throw AttendanceException::outsideRadius();
        }
    }

    /**
     * Haversine formula — distance between two GPS coordinates in meters.
     */
    public function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // metres

        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo   = deg2rad($lat2);
        $lonTo   = deg2rad($lon2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(
            pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
        ));

        return $angle * $earthRadius;
    }
}
