<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Query Profiling Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration controls query profiling and slow query logging
    | for performance monitoring and optimization.
    |
    */

    /**
     * Enable query profiling
     * Set to true in staging/production for monitoring
     */
    'enabled' => env('QUERY_PROFILING_ENABLED', false),

    /**
     * Slow query threshold in milliseconds
     * Queries exceeding this threshold will be logged
     */
    'slow_query_threshold' => env('QUERY_SLOW_THRESHOLD', 100),

    /**
     * Log query execution plans for slow queries
     * Requires additional database query per slow query
     */
    'log_execution_plans' => env('QUERY_LOG_EXECUTION_PLANS', true),

    /**
     * Maximum number of slow queries to store in memory
     * Prevents memory exhaustion during high load
     */
    'max_stored_queries' => env('QUERY_MAX_STORED', 1000),

    /**
     * Log channel for query profiling
     * Uses Laravel's logging configuration
     */
    'log_channel' => env('QUERY_LOG_CHANNEL', 'daily'),

    /**
     * Store slow queries in database
     * Enables dashboard and historical analysis
     */
    'store_in_database' => env('QUERY_STORE_IN_DB', true),

    /**
     * Retention period for stored queries (days)
     * Older queries will be automatically cleaned up
     */
    'retention_days' => env('QUERY_RETENTION_DAYS', 30),

    /**
     * Sample rate for query profiling (0.0 to 1.0)
     * 1.0 = profile all queries, 0.1 = profile 10% of queries
     * Useful for high-traffic production environments
     */
    'sample_rate' => env('QUERY_SAMPLE_RATE', 1.0),

    /**
     * Exclude patterns from profiling
     * Queries matching these patterns will not be profiled
     */
    'exclude_patterns' => [
        'migrations',
        'information_schema',
        'pg_catalog',
        'SHOW TABLES',
        'SHOW COLUMNS',
    ],

    /**
     * Alert thresholds for monitoring
     */
    'alerts' => [
        'critical_threshold' => env('QUERY_CRITICAL_THRESHOLD', 1000), // 1 second
        'warning_threshold' => env('QUERY_WARNING_THRESHOLD', 500),    // 500ms
        'alert_channel' => env('QUERY_ALERT_CHANNEL', 'slack'),
    ],

];
