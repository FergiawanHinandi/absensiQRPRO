<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class TenantContext
{
    private ?int $schoolId = null;

    private bool $isSuperAdmin = false;

    private ?int $userId = null;

    /**
     * Set the current tenant context.
     */
    public function set(int $schoolId, ?int $userId = null, bool $isSuperAdmin = false): void
    {
        $this->schoolId = $schoolId;
        $this->userId = $userId;
        $this->isSuperAdmin = $isSuperAdmin;
    }

    /**
     * Set super admin context (no specific school).
     */
    public function setSuperAdmin(int $userId): void
    {
        $this->userId = $userId;
        $this->isSuperAdmin = true;
        $this->schoolId = null;
    }

    /**
     * Get the current school ID. Throws if not set.
     *
     * @throws RuntimeException
     */
    public function getSchoolId(): int
    {
        if ($this->schoolId === null) {
            throw new RuntimeException(
                'Tenant context not initialized. Ensure TenantContextMiddleware is applied.'
            );
        }

        return $this->schoolId;
    }

    /**
     * Explicit alias for getSchoolId() to express intent.
     *
     * @throws RuntimeException
     */
    public function requireSchoolId(): int
    {
        return $this->getSchoolId();
    }

    /**
     * Get the current school ID or null if not set.
     */
    public function getSchoolIdOrNull(): ?int
    {
        return $this->schoolId;
    }

    /**
     * Get the current user ID.
     */
    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function isSuperAdmin(): bool
    {
        return $this->isSuperAdmin;
    }

    public function isSet(): bool
    {
        return $this->schoolId !== null || $this->isSuperAdmin;
    }

    /**
     * Reset context (useful for testing and queue workers).
     */
    public function reset(): void
    {
        $this->schoolId = null;
        $this->userId = null;
        $this->isSuperAdmin = false;
    }

    /**
     * Get context as array for logging.
     */
    public function toArray(): array
    {
        return [
            'school_id' => $this->schoolId,
            'user_id' => $this->userId,
            'is_super_admin' => $this->isSuperAdmin,
        ];
    }
}
