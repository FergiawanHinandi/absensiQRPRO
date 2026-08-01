<?php

namespace App\Services\Session;

use Illuminate\Support\Facades\DB;
use SessionHandlerInterface;

/**
 * Database Session Handler
 * 
 * Provides database-based session storage as a fallback when Redis is unavailable.
 * Uses the standard Laravel sessions table for storage.
 * 
 * Features:
 * - Database persistence for session data
 * - Compatible with Laravel's sessions table structure
 * - Automatic garbage collection of expired sessions
 * 
 * @author Redis HA Team
 * @version 1.0.0
 */
class DatabaseSessionHandler implements SessionHandlerInterface
{
    private $table;
    private $ttl;

    /**
     * Create a new database session handler instance.
     *
     * @param string $table Database table name
     * @param int $ttl Session TTL in seconds
     */
    public function __construct(string $table = 'sessions', int $ttl = 7200)
    {
        $this->table = $table;
        $this->ttl = $ttl;
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
        $session = DB::table($this->table)
            ->where('id', $sessionId)
            ->first();

        if ($session) {
            // Check if session is expired
            if ($session->last_activity < (time() - $this->ttl)) {
                return '';
            }
            
            return $session->payload ?? '';
        }

        return '';
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
        $payload = [
            'payload' => $sessionData,
            'last_activity' => time(),
        ];

        // Get user_id and ip_address from request if available
        if (request()) {
            $payload['user_id'] = auth()->id();
            $payload['ip_address'] = request()->ip();
            $payload['user_agent'] = request()->userAgent();
        }

        DB::table($this->table)->updateOrInsert(
            ['id' => $sessionId],
            $payload
        );

        return true;
    }

    /**
     * Destroy a session.
     *
     * @param string $sessionId
     * @return bool
     */
    public function destroy($sessionId): bool
    {
        DB::table($this->table)
            ->where('id', $sessionId)
            ->delete();

        return true;
    }

    /**
     * Garbage collection - clean up expired sessions.
     *
     * @param int $maxLifetime
     * @return int|false
     */
    public function gc($maxLifetime): int|false
    {
        $expiredTime = time() - $maxLifetime;
        
        $deleted = DB::table($this->table)
            ->where('last_activity', '<', $expiredTime)
            ->delete();

        return $deleted;
    }
}
