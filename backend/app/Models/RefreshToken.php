<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * RefreshToken Model
 * 
 * Secure refresh token for token rotation flow.
 * Tokens are stored hashed; plaintext is only available at creation.
 * 
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property int|null $personal_access_token_id
 * @property string|null $device_fingerprint
 * @property string|null $device_name
 * @property string|null $user_agent
 * @property string|null $platform
 * @property string|null $initial_ip
 * @property string|null $initial_country
 * @property string|null $last_ip
 * @property string|null $last_country
 * @property \Carbon\Carbon $expires_at
 * @property \Carbon\Carbon|null $last_used_at
 * @property \Carbon\Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property int|null $previous_token_id
 * @property int $rotation_count
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class RefreshToken extends Model
{
    use HasFactory;

    /**
     * Refresh token validity period (7 days)
     */
    public const EXPIRATION_DAYS = 7;

    /**
     * Maximum rotation count before forcing re-login
     */
    public const MAX_ROTATIONS = 100;

    /**
     * Revocation reasons
     */
    public const REVOKED_MANUAL = 'manual';
    public const REVOKED_ROTATION = 'rotation';
    public const REVOKED_PASSWORD_CHANGE = 'password_change';
    public const REVOKED_LOGOUT = 'logout';
    public const REVOKED_EXPIRED = 'expired';
    public const REVOKED_DEVICE_MISMATCH = 'device_mismatch';
    public const REVOKED_IP_COUNTRY_CHANGE = 'ip_country_change';
    public const REVOKED_ABUSE = 'abuse_detected';
    public const REVOKED_ADMIN = 'admin_revoked';

    protected $fillable = [
        'user_id',
        'token_hash',
        'personal_access_token_id',
        'device_fingerprint',
        'device_name',
        'user_agent',
        'platform',
        'initial_ip',
        'initial_country',
        'last_ip',
        'last_country',
        'expires_at',
        'last_used_at',
        'revoked_at',
        'revoked_reason',
        'previous_token_id',
        'rotation_count',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'rotation_count' => 'integer',
    ];

    /**
     * Hidden attributes (never expose token hash)
     */
    protected $hidden = [
        'token_hash',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(
            \Laravel\Sanctum\PersonalAccessToken::class,
            'personal_access_token_id'
        );
    }

    public function previousToken(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_token_id');
    }

    // =========================================================================
    // TOKEN CREATION
    // =========================================================================

    /**
     * Create a new refresh token for a user.
     * Returns the plaintext token (only available at creation).
     */
    public static function createForUser(
        User $user,
        ?int $accessTokenId = null,
        array $deviceInfo = [],
        ?int $previousTokenId = null,
        int $rotationCount = 0
    ): array {
        // Generate secure random token
        $plaintext = Str::random(64);
        $hash = hash('sha256', $plaintext);

        $token = self::create([
            'user_id' => $user->id,
            'token_hash' => $hash,
            'personal_access_token_id' => $accessTokenId,
            'device_fingerprint' => $deviceInfo['fingerprint'] ?? null,
            'device_name' => $deviceInfo['device_name'] ?? null,
            'user_agent' => isset($deviceInfo['user_agent']) 
                ? substr($deviceInfo['user_agent'], 0, 500) 
                : null,
            'platform' => $deviceInfo['platform'] ?? null,
            'initial_ip' => $deviceInfo['ip'] ?? null,
            'initial_country' => $deviceInfo['country'] ?? null,
            'last_ip' => $deviceInfo['ip'] ?? null,
            'last_country' => $deviceInfo['country'] ?? null,
            'expires_at' => now()->addDays(self::EXPIRATION_DAYS),
            'previous_token_id' => $previousTokenId,
            'rotation_count' => $rotationCount,
        ]);

        return [
            'token' => $token,
            'plaintext' => $plaintext,
        ];
    }

    /**
     * Find a token by its plaintext value.
     */
    public static function findByPlaintext(string $plaintext): ?self
    {
        $hash = hash('sha256', $plaintext);
        return self::where('token_hash', $hash)->first();
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================

    /**
     * Check if token is valid (not expired, not revoked).
     */
    public function isValid(): bool
    {
        return !$this->isExpired() && !$this->isRevoked();
    }

    /**
     * Check if token is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if token is revoked.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Check if rotation limit reached.
     */
    public function hasReachedRotationLimit(): bool
    {
        return $this->rotation_count >= self::MAX_ROTATIONS;
    }

    // =========================================================================
    // TOKEN OPERATIONS
    // =========================================================================

    /**
     * Mark token as used.
     */
    public function markAsUsed(?string $ip = null, ?string $country = null): void
    {
        $this->update([
            'last_used_at' => now(),
            'last_ip' => $ip ?? $this->last_ip,
            'last_country' => $country ?? $this->last_country,
        ]);
    }

    /**
     * Revoke the token.
     */
    public function revoke(string $reason = self::REVOKED_MANUAL): void
    {
        $this->update([
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ]);

        // Also revoke associated access token
        if ($this->personal_access_token_id) {
            $this->accessToken?->delete();
        }
    }

    // =========================================================================
    // QUERY SCOPES
    // =========================================================================

    /**
     * Only valid (not expired, not revoked) tokens.
     */
    public function scopeValid($query)
    {
        return $query
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Only active tokens for a user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Tokens by device fingerprint.
     */
    public function scopeByDevice($query, string $fingerprint)
    {
        return $query->where('device_fingerprint', $fingerprint);
    }

    /**
     * Recently used tokens.
     */
    public function scopeRecentlyUsed($query, int $minutes = 30)
    {
        return $query->where('last_used_at', '>=', now()->subMinutes($minutes));
    }

    // =========================================================================
    // BULK OPERATIONS
    // =========================================================================

    /**
     * Revoke all tokens for a user.
     */
    public static function revokeAllForUser(int $userId, string $reason = self::REVOKED_MANUAL): int
    {
        $tokens = self::forUser($userId)->valid()->get();
        
        foreach ($tokens as $token) {
            $token->revoke($reason);
        }

        return $tokens->count();
    }

    /**
     * Revoke all tokens except the current one.
     */
    public static function revokeAllExcept(int $userId, int $exceptTokenId, string $reason = self::REVOKED_MANUAL): int
    {
        $tokens = self::forUser($userId)
            ->valid()
            ->where('id', '!=', $exceptTokenId)
            ->get();
        
        foreach ($tokens as $token) {
            $token->revoke($reason);
        }

        return $tokens->count();
    }

    /**
     * Clean up expired tokens (for scheduled task).
     */
    public static function cleanupExpired(): int
    {
        return self::where('expires_at', '<', now()->subDays(7))
            ->whereNotNull('revoked_at')
            ->delete();
    }
}
