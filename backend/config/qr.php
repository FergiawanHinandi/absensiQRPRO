<?php

return [
    /*
    |--------------------------------------------------------------------------
    | QR Code Secret Key
    |--------------------------------------------------------------------------
    |
    | IMPORTANT: This MUST be different from APP_KEY
    | Used for HMAC signature of QR tokens
    | Can be rotated without invalidating old tokens (grace period)
    |
    */
    'secret' => env('QR_SECRET_KEY', 'change-this-in-production-must-be-32-chars-minimum'),

    /*
    |--------------------------------------------------------------------------
    | QR Code Expiry
    |--------------------------------------------------------------------------
    |
    | How long QR code remains valid in minutes
    | Default: 10 minutes
    |
    */
    'expiry_minutes' => env('QR_EXPIRY_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Signature Expiration (Seconds)
    |--------------------------------------------------------------------------
    |
    | CRITICAL: Anti-replay protection window
    | How long a signed QR code remains valid in seconds
    | Default: 10 seconds (for real-time attendance scanning)
    |
    */
    'signature_expiration_seconds' => env('QR_SIGNATURE_EXPIRY_SECONDS', 10),

    /*
    |--------------------------------------------------------------------------
    | Max Age Hours
    |--------------------------------------------------------------------------
    |
    | Prevent very old tokens from being reused
    | Even if signature is valid, reject tokens older than this
    |
    */
    'max_age_hours' => env('QR_MAX_AGE_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Student Card Max Age (Days)
    |--------------------------------------------------------------------------
    |
    | CRITICAL: How long student QR cards remain valid
    | Default: 365 days (1 year)
    |
    */
    'student_card_max_age_days' => env('QR_STUDENT_CARD_MAX_AGE_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | CRITICAL: Prevent QR scanning abuse
    |
    */
    'scan_rate_limit' => [
        'max_attempts' => env('QR_SCAN_MAX_ATTEMPTS', 5),
        'decay_minutes' => env('QR_SCAN_DECAY_MINUTES', 1),
    ],
];
