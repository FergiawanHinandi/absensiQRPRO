<?php

namespace App\Services\Security;

use Carbon\Carbon;

/**
 * DeviceFingerprint Data Transfer Object
 *
 * Contains hashed device identification data.
 * NEVER contains raw device_id or IP address.
 */
final readonly class DeviceFingerprint
{
    public function __construct(
        /**
         * Full composite fingerprint hash
         * SHA256(salt + device_id + user_id + ip + user_agent)
         */
        public string $fingerprint,

        /**
         * Hashed device ID component
         * SHA256(salt + "device" + device_id)
         */
        public ?string $deviceHash,

        /**
         * Hashed IP address component
         * SHA256(salt + "ip" + ip_address)
         */
        public ?string $ipHash,

        /**
         * Hashed user agent component
         * SHA256(salt + "ua" + normalized_user_agent)
         */
        public ?string $userAgentHash,

        /**
         * Associated user ID (not hashed)
         */
        public ?int $userId,

        /**
         * Fingerprint generation timestamp
         */
        public Carbon $timestamp,
    ) {}

    /**
     * Get short fingerprint for logging (first 16 chars)
     */
    public function getShortFingerprint(): string
    {
        return substr($this->fingerprint, 0, 16);
    }

    /**
     * Convert to array for storage/logging
     */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'device_hash' => $this->deviceHash,
            'ip_hash' => $this->ipHash,
            'ua_hash' => $this->userAgentHash,
            'user_id' => $this->userId,
            'timestamp' => $this->timestamp->toIso8601String(),
        ];
    }

    /**
     * Check if fingerprint matches another (full match)
     */
    public function matches(DeviceFingerprint $other): bool
    {
        return hash_equals($this->fingerprint, $other->fingerprint);
    }

    /**
     * Check if device component matches (partial match)
     */
    public function deviceMatches(DeviceFingerprint $other): bool
    {
        if (! $this->deviceHash || ! $other->deviceHash) {
            return false;
        }

        return hash_equals($this->deviceHash, $other->deviceHash);
    }
}
