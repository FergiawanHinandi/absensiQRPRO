<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Auto-Scaling Policy Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for auto-scaling Laravel app servers based on various
    | performance metrics and thresholds.
    |
    */

    'enabled' => env('AUTOSCALING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Instance Limits
    |--------------------------------------------------------------------------
    */
    'instances' => [
        'minimum' => env('AUTOSCALING_MIN_INSTANCES', 2),
        'maximum' => env('AUTOSCALING_MAX_INSTANCES', 6),
        'desired' => env('AUTOSCALING_DESIRED_INSTANCES', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cooldown Periods (seconds)
    |--------------------------------------------------------------------------
    | Prevents rapid scaling by enforcing wait periods between actions.
    */
    'cooldown' => [
        'scale_up' => env('AUTOSCALING_COOLDOWN_UP', 300),      // 5 minutes
        'scale_down' => env('AUTOSCALING_COOLDOWN_DOWN', 300),  // 5 minutes
        'evaluation_period' => env('AUTOSCALING_EVAL_PERIOD', 60), // 1 minute
    ],

    /*
    |--------------------------------------------------------------------------
    | Scaling Triggers & Thresholds
    |--------------------------------------------------------------------------
    | Each metric has scale_up and scale_down thresholds.
    | Scaling occurs when threshold is breached for consecutive_periods.
    */
    'triggers' => [
        /*
        |----------------------------------------------------------------------
        | CPU Usage (percentage)
        |----------------------------------------------------------------------
        */
        'cpu_usage' => [
            'enabled' => true,
            'weight' => 30,  // Priority weight for combined scoring
            'scale_up' => [
                'threshold' => 70,           // Scale up if CPU > 70%
                'consecutive_periods' => 3,  // Must breach for 3 periods
                'instances_to_add' => 1,
            ],
            'scale_down' => [
                'threshold' => 30,           // Scale down if CPU < 30%
                'consecutive_periods' => 5,  // Must be low for 5 periods
                'instances_to_remove' => 1,
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | HTTP Request Rate (requests per second per instance)
        |----------------------------------------------------------------------
        */
        'request_rate' => [
            'enabled' => true,
            'weight' => 25,
            'scale_up' => [
                'threshold' => 100,          // Scale up if > 100 RPS/instance
                'consecutive_periods' => 2,
                'instances_to_add' => 1,
            ],
            'scale_down' => [
                'threshold' => 20,           // Scale down if < 20 RPS/instance
                'consecutive_periods' => 5,
                'instances_to_remove' => 1,
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Average Response Time (milliseconds)
        |----------------------------------------------------------------------
        */
        'response_time' => [
            'enabled' => true,
            'weight' => 25,
            'scale_up' => [
                'threshold' => 500,          // Scale up if avg > 500ms
                'consecutive_periods' => 3,
                'instances_to_add' => 1,
            ],
            'scale_down' => [
                'threshold' => 100,          // Scale down if avg < 100ms
                'consecutive_periods' => 5,
                'instances_to_remove' => 1,
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Queue Job Backlog (pending jobs)
        |----------------------------------------------------------------------
        */
        'queue_backlog' => [
            'enabled' => true,
            'weight' => 20,
            'scale_up' => [
                'threshold' => 500,          // Scale up if > 500 pending jobs
                'consecutive_periods' => 2,
                'instances_to_add' => 2,     // Add 2 for queue pressure
            ],
            'scale_down' => [
                'threshold' => 50,           // Scale down if < 50 pending jobs
                'consecutive_periods' => 5,
                'instances_to_remove' => 1,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Aggressive Scaling (Emergency)
    |--------------------------------------------------------------------------
    | Bypass cooldown for critical thresholds.
    */
    'aggressive_scaling' => [
        'enabled' => true,
        'triggers' => [
            'cpu_usage' => 90,           // Immediate scale at 90% CPU
            'response_time' => 2000,     // Immediate scale at 2s response
            'queue_backlog' => 2000,     // Immediate scale at 2000 jobs
            'error_rate' => 5,           // Immediate scale at 5% error rate
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled Scaling
    |--------------------------------------------------------------------------
    | Predictive scaling based on known traffic patterns.
    */
    'scheduled_scaling' => [
        'enabled' => env('AUTOSCALING_SCHEDULED', false),
        'schedules' => [
            // School hours - higher capacity
            [
                'name' => 'school_hours_start',
                'cron' => '0 6 * * 1-5',  // 6 AM Mon-Fri
                'desired_instances' => 4,
            ],
            [
                'name' => 'school_hours_peak',
                'cron' => '0 7 * * 1-5',  // 7 AM Mon-Fri (attendance peak)
                'desired_instances' => 6,
            ],
            [
                'name' => 'school_hours_end',
                'cron' => '0 15 * * 1-5', // 3 PM Mon-Fri
                'desired_instances' => 3,
            ],
            // Off hours - minimum capacity
            [
                'name' => 'off_hours',
                'cron' => '0 20 * * *',   // 8 PM daily
                'desired_instances' => 2,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloud Provider Configuration
    |--------------------------------------------------------------------------
    */
    'provider' => env('AUTOSCALING_PROVIDER', 'aws'), // aws, gcp, azure, kubernetes

    'aws' => [
        'auto_scaling_group' => env('AWS_ASG_NAME'),
        'region' => env('AWS_DEFAULT_REGION', 'ap-southeast-1'),
        'launch_template_id' => env('AWS_LAUNCH_TEMPLATE_ID'),
    ],

    'kubernetes' => [
        'deployment' => env('K8S_DEPLOYMENT_NAME', 'absensi-api'),
        'namespace' => env('K8S_NAMESPACE', 'production'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        'enabled' => true,
        'channels' => ['slack', 'log'],
        'notify_on' => [
            'scale_up' => true,
            'scale_down' => true,
            'limit_reached' => true,
            'aggressive_scale' => true,
        ],
    ],
];
