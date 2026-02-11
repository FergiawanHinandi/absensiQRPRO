<?php

declare(strict_types=1);

namespace App\Domain\Attendance\ValueObjects;

use App\Domain\Shared\ValueObject;

final class GeoFence extends ValueObject
{
    private const EARTH_RADIUS_METERS = 6371000;

    public function __construct(
        public readonly float $lat,
        public readonly float $lng,
        public readonly int $radiusMeters = 100,
    ) {}

    /**
     * Check if coordinates are within the geo fence.
     */
    public function contains(float $lat, float $lng): bool
    {
        return $this->distanceTo($lat, $lng) <= $this->radiusMeters;
    }

    /**
     * Calculate distance in meters using Haversine formula.
     */
    public function distanceTo(float $lat, float $lng): float
    {
        $latFrom = deg2rad($this->lat);
        $latTo = deg2rad($lat);
        $lonFrom = deg2rad($this->lng);
        $lonTo = deg2rad($lng);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $a = sin($latDelta / 2) ** 2
            + cos($latFrom) * cos($latTo) * sin($lonDelta / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_METERS * $c;
    }

    public function toArray(): array
    {
        return [
            'lat' => $this->lat,
            'lng' => $this->lng,
            'radius_meters' => $this->radiusMeters,
        ];
    }
}
