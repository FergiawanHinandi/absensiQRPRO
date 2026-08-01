<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Konfigurasi Sekolah Default
    |--------------------------------------------------------------------------
    |
    | File ini berisi konfigurasi default untuk deployment
    | SD Negeri Unggulan Mongisidi 1.
    |
    */

    'default' => [
        'npsn' => env('SCHOOL_NPSN', '40302761'),
        'name' => env('SCHOOL_NAME', 'SD Negeri Unggulan Mongisidi 1'),
        'level' => env('SCHOOL_LEVEL', 'SD'),
        'timezone' => env('SCHOOL_TIMEZONE', 'Asia/Makassar'),
        'address' => env('SCHOOL_ADDRESS', 'Jl. Mongisidi No. 1, Makassar'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rombel (Rombongan Belajar)
    |--------------------------------------------------------------------------
    |
    | Daftar kelas yang tersedia di sekolah.
    | Format: {Tingkat}{Paralel}
    |
    */
    'rombel' => [
        '1A', '1B',
        '2A', '2B',
        '3A', '3B',
        '4A', '4B',
        '5A', '5B',
        '6A', '6B',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pengaturan Absensi
    |--------------------------------------------------------------------------
    */
    'attendance' => [
        'qr_expiry_minutes' => env('QR_EXPIRY_MINUTES', 10),
        'late_tolerance_minutes' => env('LATE_TOLERANCE_MINUTES', 15),
        'fingerprint_enabled' => env('FINGERPRINT_ENABLED', true),
        'rfid_enabled' => env('RFID_ENABLED', true),
        'qr_mode' => env('QR_MODE', 'per_session'),
        'allow_manual_input' => env('ALLOW_MANUAL_INPUT', true),
        'location_required' => env('LOCATION_REQUIRED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Perangkat Solution X606-S
    |--------------------------------------------------------------------------
    */
    'device' => [
        'sn' => env('DEVICE_SN', 'X606S-MGS-2024-001'),
        'model' => env('DEVICE_MODEL', 'Solution X606-S'),
        'protocol' => env('DEVICE_PROTOCOL', 'ADMS'),
        'ip_address' => env('DEVICE_IP', '192.168.1.100'),
        'port' => env('DEVICE_PORT', 8080),
        'push_interval' => env('DEVICE_PUSH_INTERVAL', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | ID Card (CR80 Standard)
    |--------------------------------------------------------------------------
    */
    'id_card' => [
        'width_mm' => 85.6, // CR80 standard
        'height_mm' => 54.0, // CR80 standard
        'dpi' => 300,
        'logo_path' => env('ID_CARD_LOGO', 'images/logo-school.png'),
        'show_nisn' => true,
        'show_rfid' => env('ID_CARD_SHOW_RFID', true),
        'show_qr' => env('ID_CARD_SHOW_QR', true),
        'background_color' => env('ID_CARD_BG_COLOR', '#1e40af'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Batas Sistem
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'max_students_per_class' => env('MAX_STUDENTS_PER_CLASS', 32),
        'max_classes' => env('MAX_CLASSES', 12),
        'max_teachers' => env('MAX_TEACHERS', 30),
    ],
];
