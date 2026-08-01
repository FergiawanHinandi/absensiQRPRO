<?php

namespace Tests\Feature\Redis;

use App\Services\Session\TenantAwareSessionHandler;

/**
 * Test helper class for TenantAwareSessionHandler
 * 
 * This class extends TenantAwareSessionHandler to allow testing with
 * a forced tenant ID, bypassing the normal authentication-based tenant detection.
 */
class TestTenantAwareSessionHandler extends TenantAwareSessionHandler
{
    private $forcedTenantId;
    
    /**
     * Create a new test tenant-aware session handler instance.
     *
     * @param mixed $redis Redis connection
     * @param string $keyPrefix Key prefix for session storage
     * @param int $ttl Session TTL in seconds
     * @param int|string $forcedTenantId Tenant ID to use for testing
     */
    public function __construct($redis, string $keyPrefix, int $ttl, $forcedTenantId)
    {
        parent::__construct($redis, $keyPrefix, $ttl);
        $this->forcedTenantId = $forcedTenantId;
    }
    
    /**
     * Override to use forced tenant ID for testing
     *
     * @return int|string
     */
    protected function getCurrentTenantId()
    {
        return $this->forcedTenantId;
    }
}
