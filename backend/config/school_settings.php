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
];
