<?php

/**
 * Critical Rate Limiting Configuration
 *
 * Multi-tenant sliding window rate limits for AbsensiQR Pro.
 * Values can be overridden via environment variables.
 *
 * Spec: critical-rate-limiting / requirements.md
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Global Rate Limiting Settings
    |--------------------------------------------------------------------------
    */
    'enabled' => env('RATE_LIMITING_ENABLED', true),

    /*
    | Redis connection to use for rate limit counters.
    | Separate from cache/session to avoid interference.
    */
    'redis_connection' => env('RATE_LIMIT_REDIS_CONNECTION', 'default'),

    /*
    | Prefix for all rate limit Redis keys (ensures no collisions).
    */
    'key_prefix' => env('RATE_LIMIT_KEY_PREFIX', 'rl'),

    /*
    | Fallback behavior when Redis is unavailable:
    | - 'allow'  : Allow all requests (fail-open, safer for UX)
    | - 'deny'   : Deny all requests (fail-closed, safer for security)
    | - 'local'  : Use in-memory array counter (loses precision across workers)
    */
    'redis_fallback' => env('RATE_LIMIT_FALLBACK', 'deny'),

    /*
    |--------------------------------------------------------------------------
    | Endpoint-Specific Rate Limits
    |--------------------------------------------------------------------------
    |
    | Format: 'max_attempts' per 'decay_seconds' window.
    | 'type' options: 'ip', 'user', 'school', 'device', 'composite'
    */
    'endpoints' => [

        'login' => [
            'max_attempts'  => (int) env('RATE_LIMIT_LOGIN', 5),
            'decay_seconds' => 300,    // 5 minutes
            'key_type'      => 'ip',   // IP-based: prevent brute force
            'description'   => 'Login attempts per IP address',
            'log_violations' => true,
            'block_on_violation' => false,
        ],

        'qr_scan' => [
            'max_attempts'  => (int) env('RATE_LIMIT_QR_SCAN', 30),
            'decay_seconds' => 60,         // 1 minute
            'key_type'      => 'composite', // user + device + school
            'description'   => 'QR scan attempts per user/device',
            'log_violations' => true,
            'block_on_violation' => false,
        ],

        'export' => [
            'max_attempts'  => (int) env('RATE_LIMIT_EXPORT', 10),
            'decay_seconds' => 3600,     // 1 hour
            'key_type'      => 'school', // School-based resource protection
            'description'   => 'Export operations per school per hour',
            'log_violations' => true,
            'block_on_violation' => false,
        ],

        'password_reset' => [
            'max_attempts'  => (int) env('RATE_LIMIT_PASSWORD_RESET', 3),
            'decay_seconds' => 3600,  // 1 hour
            'key_type'      => 'ip',  // IP-based
            'description'   => 'Password reset attempts per IP per hour',
            'log_violations' => true,
            'block_on_violation' => true, // Block on violation — security critical
        ],

        'api_general' => [
            'max_attempts'  => (int) env('RATE_LIMIT_API', 120),
            'decay_seconds' => 60,
            'key_type'      => 'user',
            'description'   => 'General API requests per user per minute',
            'log_violations' => false,
            'block_on_violation' => false,
        ],

        'webhook' => [
            'max_attempts'  => (int) env('RATE_LIMIT_WEBHOOK', 100),
            'decay_seconds' => 60,
            'key_type'      => 'ip',
            'description'   => 'Webhook calls per IP per minute',
            'log_violations' => true,
            'block_on_violation' => false,
        ],

        'face_recognition' => [
            'max_attempts'  => (int) env('RATE_LIMIT_FACE_RECOG', 20),
            'decay_seconds' => 60,
            'key_type'      => 'user',
            'description'   => 'Face recognition requests per user per minute',
            'log_violations' => true,
            'block_on_violation' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin Bypass Configuration
    |--------------------------------------------------------------------------
    |
    | Roles that are exempt from rate limiting.
    | Bypass events are still logged for audit trail.
    */
    'bypass' => [
        'enabled' => env('RATE_LIMIT_BYPASS_ENABLED', true),

        // Roles completely exempt from ALL rate limits
        'exempt_roles' => ['super_admin'],

        // Roles exempt from specific endpoint limits
        'role_exemptions' => [
            'school_admin' => ['export', 'api_general'],
        ],

        // IPs exempt from rate limiting (e.g., internal monitoring)
        'exempt_ips' => explode(',', env('RATE_LIMIT_EXEMPT_IPS', '')),

        // Log bypass events for audit
        'log_bypasses' => true,

        // Cache bypass permissions in Redis for this many seconds
        'cache_ttl' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sliding Window Algorithm Settings
    |--------------------------------------------------------------------------
    */
    'sliding_window' => [
        // Use Redis sorted sets for precise sliding window
        'use_sorted_sets' => true,

        // Cleanup old entries every N requests (performance optimization)
        'cleanup_interval' => 10,

        // Max precision: how many sub-windows per window
        'precision' => 1, // 1 = exact sliding window
    ],

    /*
    |--------------------------------------------------------------------------
    | Violation Logging & Monitoring
    |--------------------------------------------------------------------------
    */
    'monitoring' => [
        // Log channel for rate limit violations
        'log_channel' => env('RATE_LIMIT_LOG_CHANNEL', 'security'),

        // Store violations in database for analysis
        'store_violations' => env('RATE_LIMIT_STORE_VIOLATIONS', true),

        // Database retention: keep violations for N days
        'retention_days' => 30,

        // Alert when violation rate exceeds threshold in 5 minutes
        'alert_threshold' => 100,

        // Send alerts via these channels
        'alert_channels' => explode(',', env('RATE_LIMIT_ALERT_CHANNELS', 'log')),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Response Headers
    |--------------------------------------------------------------------------
    */
    'headers' => [
        'limit'     => 'X-RateLimit-Limit',
        'remaining' => 'X-RateLimit-Remaining',
        'reset'     => 'X-RateLimit-Reset',
        'retry_after' => 'Retry-After',
        'type'      => 'X-RateLimit-Type',
    ],

];
