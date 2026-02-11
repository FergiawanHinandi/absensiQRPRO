<?php

return [
    /*
    |--------------------------------------------------------------------------
    | QR Code Time Tolerance
    |--------------------------------------------------------------------------
    |
    | Waktu toleransi (dalam menit) sebelum dan sesudah jadwal mengajar
    | untuk generate QR code. Contoh: 15 menit = guru bisa generate QR
    | 15 menit sebelum jadwal dimulai dan 15 menit setelah jadwal selesai.
    |
    */
    'qr_time_tolerance' => env('QR_TIME_TOLERANCE', 15),

    /*
    |--------------------------------------------------------------------------
    | Strict Time Validation
    |--------------------------------------------------------------------------
    |
    | Jika true, validasi waktu akan diterapkan secara ketat.
    | Jika false, validasi waktu akan lebih fleksibel.
    |
    */
    'strict_time_validation' => env('QR_STRICT_TIME', true),

    /*
    |--------------------------------------------------------------------------
    | Allow Admin Bypass
    |--------------------------------------------------------------------------
    |
    | Jika true, admin/principal dapat generate QR di luar waktu jadwal.
    | Jika false, semua user harus mengikuti validasi waktu.
    |
    */
    'allow_admin_bypass' => env('QR_ADMIN_BYPASS', true),

    /*
    |--------------------------------------------------------------------------
    | QR Code Expiry
    |--------------------------------------------------------------------------
    |
    | Waktu kadaluarsa QR code dalam detik.
    | Default: 60 detik
    | Min: 30 detik, Max: 300 detik (5 menit)
    |
    */
    'qr_expiry_seconds' => [
        'default' => 60,
        'min' => 30,
        'max' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Day Names (Indonesian)
    |--------------------------------------------------------------------------
    |
    | Nama hari dalam bahasa Indonesia untuk error messages.
    |
    */
    'day_names' => [
        0 => 'Minggu',
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
    ],
];
