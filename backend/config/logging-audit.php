<?php

return [
    'channels' => [
        // ...existing channels...
        'audit' => [
            'driver' => 'single',
            'path' => storage_path('logs/audit.log'),
            'level' => 'info',
        ],
    ],
];
