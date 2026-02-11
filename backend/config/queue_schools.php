<?php

return [

    /*
    |--------------------------------------------------------------------------
    | School Queue Isolation Configuration
    |--------------------------------------------------------------------------
    |
    | Maps schools to dedicated or priority queue workers.
    | Enterprise-tier schools can have isolated queue workers to prevent
    | one school's load from affecting another.
    |
    */

    // Default shared queue for most schools
    'default_queue' => env('QUEUE_DEFAULT_SCHOOL', 'attendance-default'),

    // Queue for event projections (CQRS read model updates)
    'projection_queue' => env('QUEUE_PROJECTION', 'projections'),

    // Schools with fully dedicated queues: school_id => queue_name
    // These schools get exclusive worker processes
    'dedicated' => [
        // Example:
        // 1 => 'attendance-school-1',
        // 42 => 'attendance-school-42',
    ],

    // Schools that use priority queues (shared but prioritized)
    // Jobs go to "attendance-priority-{school_id}"
    'priority' => [
        // Example: 5, 10, 15
    ],

];
