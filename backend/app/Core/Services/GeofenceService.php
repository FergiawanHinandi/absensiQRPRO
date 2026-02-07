<?php

namespace App\Core\Services;

use Exception;

class GeofenceService
{
    /**
     * Earth radius in meters (WGS84 Mean Radius)
     */
    private const EARTH_RADIUS_METERS = 6371000;

    /**
     * Default maximum allowed distance in meters
     */
    private const DEFAULT_MAX_DISTANCE = 50;

    /**
     * Calculate distance between two coordinates using Haversine formula
     *
     * @param  float  $schoolLatitude  School's latitude
     * @param  float  $schoolLongitude  School's longitude
     * @param  float  $teacherLatitude  Teacher's/User's latitude
     * @param  float  $teacherLongitude  Teacher's/User's longitude
     * @return float Distance in meters
     */
    public function calculateDistance(
        float $schoolLatitude,
        float $schoolLongitude,
        float $teacherLatitude,
        float $teacherLongitude
    ): float {
        // Convert degrees to radians
        $schoolLatRad = deg2rad($schoolLatitude);
        $schoolLngRad = deg2rad($schoolLongitude);
        $teacherLatRad = deg2rad($teacherLatitude);
        $teacherLngRad = deg2rad($teacherLongitude);

        // Differences
        $latDelta = $teacherLatRad - $schoolLatRad;
        $lngDelta = $teacherLngRad - $schoolLngRad;

        // Haversine formula
        $a = pow(sin($latDelta / 2), 2) +
             cos($schoolLatRad) * cos($teacherLatRad) * pow(sin($lngDelta / 2), 2);

        $c = 2 * asin(sqrt($a));

        return self::EARTH_RADIUS_METERS * $c;
    }

    /**
     * Check if a location is within the allowed radius
     *
     * @param  float  $schoolLatitude  School's latitude
     * @param  float  $schoolLongitude  School's longitude
     * @param  float  $teacherLatitude  Teacher's/User's latitude
     * @param  float  $teacherLongitude  Teacher's/User's longitude
     * @param  float|null  $maxDistanceMeters  Maximum allowed distance (default: 50m)
     * @return bool True if within radius, false otherwise
     */
    public function isWithinRadius(
        float $schoolLatitude,
        float $schoolLongitude,
        float $teacherLatitude,
        float $teacherLongitude,
        ?float $maxDistanceMeters = null
    ): bool {
        $maxDistance = $maxDistanceMeters ?? self::DEFAULT_MAX_DISTANCE;
        $distance = $this->calculateDistance(
            $schoolLatitude,
            $schoolLongitude,
            $teacherLatitude,
            $teacherLongitude
        );

        return $distance <= $maxDistance;
    }

    /**
     * Validate that location is within geofence, throw exception if not
     *
     * @param  float  $schoolLatitude  School's latitude
     * @param  float  $schoolLongitude  School's longitude
     * @param  float  $teacherLatitude  Teacher's/User's latitude
     * @param  float  $teacherLongitude  Teacher's/User's longitude
     * @param  float|null  $maxDistanceMeters  Maximum allowed distance (default: 50m)
     * @return float The calculated distance in meters
     *
     * @throws Exception If location is outside the allowed radius
     */
    public function validateWithinRadius(
        float $schoolLatitude,
        float $schoolLongitude,
        float $teacherLatitude,
        float $teacherLongitude,
        ?float $maxDistanceMeters = null
    ): float {
        $maxDistance = $maxDistanceMeters ?? self::DEFAULT_MAX_DISTANCE;
        $distance = $this->calculateDistance(
            $schoolLatitude,
            $schoolLongitude,
            $teacherLatitude,
            $teacherLongitude
        );

        if ($distance > $maxDistance) {
            throw new Exception(
                'Anda di luar area sekolah. Jarak Anda: '.round($distance)."m (maksimal: {$maxDistance}m)."
            );
        }

        return $distance;
    }

    /**
     * Get detailed geofence check result
     *
     * @param  float  $schoolLatitude  School's latitude
     * @param  float  $schoolLongitude  School's longitude
     * @param  float  $teacherLatitude  Teacher's/User's latitude
     * @param  float  $teacherLongitude  Teacher's/User's longitude
     * @param  float|null  $maxDistanceMeters  Maximum allowed distance (default: 50m)
     * @return array{distance_meters: float, max_allowed: float, is_within_radius: bool, excess_meters: float|null}
     */
    public function check(
        float $schoolLatitude,
        float $schoolLongitude,
        float $teacherLatitude,
        float $teacherLongitude,
        ?float $maxDistanceMeters = null
    ): array {
        $maxDistance = $maxDistanceMeters ?? self::DEFAULT_MAX_DISTANCE;
        $distance = $this->calculateDistance(
            $schoolLatitude,
            $schoolLongitude,
            $teacherLatitude,
            $teacherLongitude
        );

        $isWithin = $distance <= $maxDistance;

        return [
            'distance_meters' => round($distance, 2),
            'max_allowed' => $maxDistance,
            'is_within_radius' => $isWithin,
            'excess_meters' => $isWithin ? null : round($distance - $maxDistance, 2),
        ];
    }
}
