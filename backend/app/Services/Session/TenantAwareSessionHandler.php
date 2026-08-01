<?php

namespace App\Services\Session;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use SessionHandlerInterface;

/**
 * Tenant-Aware Session Handler
 * 
 * Implements multi-tenant session isolation using school-based key prefixing.
 * Ensures session data is isolated per school tenant and persists across Redis failovers.
 * 
 * Key Format: session:tenant:{tenant_id}:session:{session_id}
 * 
 * Features:
 * - Multi-tenant isolation through key prefixing
 * - Session persistence across Redis failovers
 * - TTL-based session expiration
 * - Fallback to database when Redis is unavailable
 * 
 * @author Redis HA Team
 * @version 1.0.0
 */
class TenantAwareSessionHandler implements SessionHandlerInterface
{
    private $redis;
    private $keyPrefix;
    private $ttl;
    private $fallbackHandler;

    /**
     * Create a new tenant-aware session handler instance.
     *
     * @param mixed $redis Redis connection
     * @param string $keyPrefix Key prefix for session storage
     * @param int $ttl Session TTL in seconds
     * @param SessionHandlerInterface|null $fallbackHandler Fallback handler for Redis failures
     */
    public function __construct($redis, string $keyPrefix = 'session', int $ttl = 7200, ?SessionHandlerInterface $fallbackHandler = null)
    {
        $this->redis = $redis;
        $this->keyPrefix = $keyPrefix;
        $this->ttl = $ttl;
        $this->fallbackHandler = $fallbackHandler;
    }

    /**
     * Open session storage.
     *
     * @param string $savePath
     * @param string $sessionName
     * @return bool
     */
    public function open($savePath, $sessionName): bool
    {
        return true;
    }

    /**
     * Close session storage.
     *
     * @return bool
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * Read session data.
     *
     * @param string $sessionId
     * @return string|false
     */
    public function read($sessionId): string|false
    {
        try {
            $tenantId = $this->getCurrentTenantId();
            $key = $this->buildKey($tenantId, $sessionId);
            
            $data = $this->redis->get($key);
            
            if ($data === null || $data === false) {
                return '';
            }
            
            return $data;
        } catch (\Exception $e) {
            Log::warning('Redis session read failed, using fallback', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            
            if ($this->fallbackHandler) {
                return $this->fallbackHandler->read($sessionId);
            }
            
            return '';
        }
    }

    /**
     * Write session data.
     *
     * @param string $sessionId
     * @param string $sessionData
     * @return bool
     */
    public function write($sessionId, $sessionData): bool
    {
        try {
            $tenantId = $this->getCurrentTenantId();
            $key = $this->buildKey($tenantId, $sessionId);
            
            // Use SETEX for atomic set with expiration
            $result = $this->redis->setex($key, $this->ttl, $sessionData);
            
            return $result !== false;
        } catch (\Exception $e) {
            Log::warning('Redis session write failed, using fallback', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            
            if ($this->fallbackHandler) {
                return $this->fallbackHandler->write($sessionId, $sessionData);
            }
            
            return false;
        }
    }

    /**
     * Destroy a session.
     *
     * @param string $sessionId
     * @return bool
     */
    public function destroy($sessionId): bool
    {
        try {
            $tenantId = $this->getCurrentTenantId();
            $key = $this->buildKey($tenantId, $sessionId);
            
            $this->redis->del($key);
            
            return true;
        } catch (\Exception $e) {
            Log::warning('Redis session destroy failed, using fallback', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            
            if ($this->fallbackHandler) {
                return $this->fallbackHandler->destroy($sessionId);
            }
            
            return false;
        }
    }

    /**
     * Garbage collection - clean up expired sessions.
     *
     * @param int $maxLifetime
     * @return int|false
     */
    public function gc($maxLifetime): int|false
    {
        // Redis handles expiration automatically via TTL
        // No manual garbage collection needed
        return 0;
    }

    /**
     * Build the tenant-aware session key.
     *
     * @param int|string $tenantId
     * @param string $sessionId
     * @return string
     */
    protected function buildKey($tenantId, string $sessionId): string
    {
        return "{$this->keyPrefix}:tenant:{$tenantId}:session:{$sessionId}";
    }

    /**
     * Get the current tenant ID from authenticated user.
     *
     * @return int|string
     */
    protected function getCurrentTenantId()
    {
        // Try to get tenant ID from authenticated user
        if (Auth::check()) {
            $user = Auth::user();
            if (isset($user->school_id)) {
                return $user->school_id;
            }
        }
        
        // Fallback to session-stored tenant ID (for unauthenticated sessions)
        if (session()->has('tenant_id')) {
            return session()->get('tenant_id');
        }
        
        // Default tenant for unauthenticated sessions
        return 'guest';
    }
}
