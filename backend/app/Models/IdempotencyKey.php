<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * IdempotencyKey Model
 *
 * Stores idempotency keys to prevent replay attacks and duplicate submissions.
 *
 * USAGE:
 * - Client generates UUID and sends as X-Idempotency-Key header
 * - Middleware checks if key exists
 * - If exists: return cached response
 * - If not: process request and store key with response
 *
 * @property int $id
 * @property string $key
 * @property int $user_id
 * @property string $endpoint
 * @property string $http_method
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $device_id
 * @property string|null $response_payload
 * @property int $response_status
 * @property Carbon $expires_at
 * @property Carbon $created_at
 */
class IdempotencyKey extends Model
{
    /**
     * Disable updated_at timestamp (we only need created_at)
     */
    public const UPDATED_AT = null;

    /**
     * The table associated with the model.
     */
    protected $table = 'idempotency_keys';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'key',
        'user_id',
        'endpoint',
        'http_method',
        'ip_address',
        'user_agent',
        'device_id',
        'response_payload',
        'response_status',
        'expires_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'response_status' => 'integer',
    ];

    /**
     * Get the user that owns the idempotency key.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if the key has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Get the cached response as array.
     */
    public function getCachedResponse(): ?array
    {
        if (empty($this->response_payload)) {
            return null;
        }

        return json_decode($this->response_payload, true);
    }

    /**
     * Scope to find non-expired keys.
     */
    public function scopeNotExpired($query)
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * Scope to find expired keys (for cleanup).
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    /**
     * Find existing key for user and endpoint.
     *
     * @param string $key
     * @param int $userId
     * @param string $endpoint
     * @return static|null
     */
    public static function findExisting(string $key, int $userId, string $endpoint): ?self
    {
        return static::where('key', $key)
            ->where('user_id', $userId)
            ->where('endpoint', $endpoint)
            ->notExpired()
            ->first();
    }

    /**
     * Store a new idempotency key with response.
     *
     * @param string $key
     * @param int $userId
     * @param string $endpoint
     * @param array $responseData
     * @param int $responseStatus
     * @param int $ttlMinutes
     * @param array $metadata
     * @return static
     */
    public static function store(
        string $key,
        int $userId,
        string $endpoint,
        array $responseData,
        int $responseStatus = 200,
        int $ttlMinutes = 60,
        array $metadata = []
    ): self {
        return static::create([
            'key' => $key,
            'user_id' => $userId,
            'endpoint' => $endpoint,
            'http_method' => $metadata['http_method'] ?? 'POST',
            'ip_address' => $metadata['ip_address'] ?? null,
            'user_agent' => $metadata['user_agent'] ?? null,
            'device_id' => $metadata['device_id'] ?? null,
            'response_payload' => json_encode($responseData),
            'response_status' => $responseStatus,
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);
    }
}
