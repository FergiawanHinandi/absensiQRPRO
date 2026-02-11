<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Event Publishing Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for domain event publishing to message brokers
    |
    */

    'publishing_enabled' => env('EVENT_PUBLISHING_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Message Broker
    |--------------------------------------------------------------------------
    |
    | Supported: "kafka", "redis"
    |
    */

    'broker' => env('EVENT_BROKER', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Kafka Configuration
    |--------------------------------------------------------------------------
    */

    'kafka' => [
        'brokers' => env('KAFKA_BROKERS', 'localhost:9092'),
        'group_id' => env('KAFKA_GROUP_ID', 'attendance-app'),
        'compression' => env('KAFKA_COMPRESSION', 'snappy'),
        'auto_offset_reset' => env('KAFKA_AUTO_OFFSET_RESET', 'earliest'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Configuration
    |--------------------------------------------------------------------------
    */

    'redis' => [
        'connection' => env('EVENT_REDIS_CONNECTION', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Topics
    |--------------------------------------------------------------------------
    |
    | Map event domains to Kafka topics
    |
    */

    'topics' => [
        'attendance' => 'attendance.events',
        'billing' => 'billing.events',
        'subscription' => 'subscription.events',
        'notification' => 'notification.events',
        'audit' => 'audit.events',
        'user' => 'user.events',
    ],

    /*
    |--------------------------------------------------------------------------
    | Dead Letter Queue
    |--------------------------------------------------------------------------
    */

    'dead_letter_queue' => [
        'enabled' => true,
        'redis_key' => 'events:dead_letter_queue',
        'max_retries' => 3,
        'retry_delay' => 60, // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Versioning
    |--------------------------------------------------------------------------
    */

    'versioning' => [
        'strategy' => 'semantic', // semantic, timestamp
        'backward_compatible_months' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring
    |--------------------------------------------------------------------------
    */

    'monitoring' => [
        'log_all_events' => env('EVENT_LOG_ALL', false),
        'log_failures' => true,
        'metrics_enabled' => true,
    ],
];
