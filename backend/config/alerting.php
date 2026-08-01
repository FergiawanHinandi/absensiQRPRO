<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Alerting Configuration
    |--------------------------------------------------------------------------
    |
    | Configure alerting channels for monitoring and incident response.
    |
    */

    'enabled' => env('ALERTS_ENABLED', true),

    'slack_webhook_url' => env('SLACK_SECURITY_WEBHOOK_URL'),

    'alert_rate_limit_minutes' => env('ALERT_RATE_LIMIT_MINUTES', 60),

    'suppress_during_maintenance' => env('MAINTENANCE_SUPPRESS_ALERTS', true),

];
