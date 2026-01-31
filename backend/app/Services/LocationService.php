<?php

namespace App\Services;

use App\Models\School;
use DomainException;

/**
 * Location Validation Service
 *
 * Validates GPS coordinates against school boundaries
 * and detects suspicious patterns
 */
class LocationService
{
    /**
     * Validate location or throw exception
     *
     * @throws DomainException
     */
    public function validateOrFail(array $locationData, School $school): void
    {
        if (! isset($locationData['latitude'], $locationData['longitude'])) {
            throw new DomainException('Missing GPS coordinates');
        }

        // Calculate distance to school
        $distance = $this->haversineDistance(
            $school->latitude,
            $school->longitude,
            $locationData['latitude'],
            $locationData['longitude']
        );

        if ($distance > $school->radius_meters) {
            throw new DomainException(
                "Location out of range: {$distance}m (max: {$school->radius_meters}m)"
            );
        }

        // Optional: Check GPS accuracy
        if (isset($locationData['accuracy']) && $locationData['accuracy'] > 50) {
            \Log::warning('Poor GPS accuracy', [
                'accuracy' => $locationData['accuracy'],
                'user_id' => auth()->id(),
            ]);
        }
    }

    /**
     * Calculate distance between two GPS coordinates using Haversine formula
     *
     * @return float Distance in meters
     */
    public function haversineDistance(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        $earthRadius = 6371000; // meters

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
