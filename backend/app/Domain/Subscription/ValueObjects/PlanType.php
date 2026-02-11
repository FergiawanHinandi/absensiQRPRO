<?php

declare(strict_types=1);

namespace App\Domain\Subscription\ValueObjects;

use App\Domain\Shared\ValueObject;

final class PlanType extends ValueObject
{
    public const FREE = 'free';

    public const BASIC = 'basic';

    public const PREMIUM = 'premium';

    public const ENTERPRISE = 'enterprise';

    private const VALID_TYPES = [
        self::FREE,
        self::BASIC,
        self::PREMIUM,
        self::ENTERPRISE,
    ];

    private const PLAN_HIERARCHY = [
        self::FREE => 0,
        self::BASIC => 1,
        self::PREMIUM => 2,
        self::ENTERPRISE => 3,
    ];

    public readonly string $value;

    public function __construct(string $value)
    {
        $normalized = strtolower(trim($value));

        if (! in_array($normalized, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Invalid plan type [{$value}]. Valid: " . implode(', ', self::VALID_TYPES)
            );
        }

        $this->value = $normalized;
    }

    public static function free(): self
    {
        return new self(self::FREE);
    }

    public static function basic(): self
    {
        return new self(self::BASIC);
    }

    public static function premium(): self
    {
        return new self(self::PREMIUM);
    }

    public static function enterprise(): self
    {
        return new self(self::ENTERPRISE);
    }

    /**
     * Check if this plan is at least the given tier.
     */
    public function isAtLeast(self $other): bool
    {
        return self::PLAN_HIERARCHY[$this->value] >= self::PLAN_HIERARCHY[$other->value];
    }

    /**
     * Check if this plan includes a specific feature.
     */
    public function includes(string $feature): bool
    {
        $features = $this->defaultFeatures();

        return in_array($feature, $features, true);
    }

    public function defaultFeatures(): array
    {
        return match ($this->value) {
            self::FREE => ['basic_attendance', 'qr_scan'],
            self::BASIC => ['basic_attendance', 'qr_scan', 'reports', 'manual_attendance'],
            self::PREMIUM => ['basic_attendance', 'qr_scan', 'reports', 'manual_attendance', 'geo_fence', 'export', 'notifications'],
            self::ENTERPRISE => ['basic_attendance', 'qr_scan', 'reports', 'manual_attendance', 'geo_fence', 'export', 'notifications', 'api_access', 'sso', 'audit_log', 'dedicated_queue'],
        };
    }

    public function toArray(): array
    {
        return ['value' => $this->value];
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
