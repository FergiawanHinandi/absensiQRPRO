<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Webhook Lock Timeout
    |--------------------------------------------------------------------------
    |
    | The number of seconds to hold the Redis lock when processing webhooks.
    | This prevents concurrent processing of duplicate webhooks.
    | Default: 300 seconds (5 minutes)
    |
    */
    'lock_timeout' => env('WEBHOOK_LOCK_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | Webhook Lock Acquisition Timeout
    |--------------------------------------------------------------------------
    |
    | The number of seconds to wait when trying to acquire a webhook lock.
    | If the lock cannot be acquired within this time, the request will fail.
    | Default: 60 seconds (1 minute)
    |
    */
    'lock_acquisition_timeout' => env('WEBHOOK_LOCK_ACQUISITION_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Webhook Processing Timeout
    |--------------------------------------------------------------------------
    |
    | The maximum number of seconds a webhook should take to process.
    | Used for monitoring and alerting on long-running webhook processing.
    | Default: 120 seconds (2 minutes)
    |
    */
    'processing_timeout' => env('WEBHOOK_PROCESSING_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Enable Webhook Monitoring
    |--------------------------------------------------------------------------
    |
    | Enable monitoring and metrics collection for webhook processing.
    | This includes lock acquisition time, processing duration, and failures.
    |
    */
    'enable_monitoring' => env('WEBHOOK_ENABLE_MONITORING', true),
];
