<?php

return [
    'scan_grace_period_min' => [
        'type' => 'int',
        'min' => 0,
        'max' => 60,
        'default' => 10,
    ],
    'min_gps_accuracy_m' => [
        'type' => 'int',
        'min' => 0,
        'max' => 200,
        'default' => 50,
    ],
    'require_exam_code' => [
        'type' => 'bool',
        'default' => false,
    ],
    'auto_close_qr_after_min' => [
        'type' => 'int',
        'min' => 1,
        'max' => 180,
        'default' => 15,
    ],
    'allow_offsite_scan' => [
        'type' => 'bool',
        'default' => false,
    ],

    // ================================================
    // NEW FIELDS (User Request)
    // ================================================

    // QR Mode
    'qr_expiry_seconds' => [
        'type' => 'int',
        'min' => 5,
        'max' => 60,
        'default' => 30,
    ],
    'qr_regeneration_cooldown' => [
        'type' => 'int',
        'min' => 0,
        'max' => 30,
        'default' => 5,
    ],

    // Override
    'allow_teacher_override' => [
        'type' => 'bool',
        'default' => true,
    ],
    'require_override_reason' => [
        'type' => 'bool',
        'default' => true,
    ],

    // Tolerance
    'late_tolerance_minutes' => [
        'type' => 'int',
        'min' => 0,
        'max' => 120,
        'default' => 15,
    ],
    'early_check_in_allowed' => [
        'type' => 'bool',
        'default' => true,
    ],
];
