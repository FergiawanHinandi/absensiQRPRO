<?php

/**
 * Test Script: QR Time Validation
 * 
 * Test validasi waktu generate QR dengan berbagai skenario
 */

require __DIR__ . '/vendor/autoload.php';

use Carbon\Carbon;

// Simulate schedule data
$schedule = (object) [
    'id' => 123,
    'day_of_week' => 1, // Senin
    'start_time' => '08:00:00',
    'end_time' => '09:00:00',
    'timezone' => 'Asia/Jakarta',
];

$tolerance = 15; // minutes

echo "=== TEST QR TIME VALIDATION ===\n\n";
echo "Jadwal: " . getDayName($schedule->day_of_week) . ", {$schedule->start_time} - {$schedule->end_time}\n";
echo "Tolerance: ±{$tolerance} menit\n";
echo "Allowed Range: " . Carbon::parse($schedule->start_time)->subMinutes($tolerance)->format('H:i:s') . " - " . Carbon::parse($schedule->end_time)->addMinutes($tolerance)->format('H:i:s') . "\n\n";

// Test scenarios
$testCases = [
    // Valid cases
    ['day' => 1, 'time' => '07:45:00', 'expected' => true, 'desc' => 'Tepat di awal tolerance (15 menit sebelum)'],
    ['day' => 1, 'time' => '08:00:00', 'expected' => true, 'desc' => 'Tepat waktu mulai'],
    ['day' => 1, 'time' => '08:30:00', 'expected' => true, 'desc' => 'Di tengah jadwal'],
    ['day' => 1, 'time' => '09:00:00', 'expected' => true, 'desc' => 'Tepat waktu selesai'],
    ['day' => 1, 'time' => '09:15:00', 'expected' => true, 'desc' => 'Tepat di akhir tolerance (15 menit sesudah)'],
    
    // Invalid cases - time
    ['day' => 1, 'time' => '07:30:00', 'expected' => false, 'desc' => 'Terlalu awal (30 menit sebelum)'],
    ['day' => 1, 'time' => '07:44:59', 'expected' => false, 'desc' => 'Sedikit di luar tolerance awal'],
    ['day' => 1, 'time' => '09:16:00', 'expected' => false, 'desc' => 'Sedikit di luar tolerance akhir'],
    ['day' => 1, 'time' => '09:30:00', 'expected' => false, 'desc' => 'Terlalu terlambat (30 menit sesudah)'],
    ['day' => 1, 'time' => '15:00:00', 'expected' => false, 'desc' => 'Jauh di luar jadwal'],
    
    // Invalid cases - day
    ['day' => 0, 'time' => '08:00:00', 'expected' => false, 'desc' => 'Hari Minggu (bukan Senin)'],
    ['day' => 2, 'time' => '08:00:00', 'expected' => false, 'desc' => 'Hari Selasa (bukan Senin)'],
    ['day' => 6, 'time' => '08:00:00', 'expected' => false, 'desc' => 'Hari Sabtu (bukan Senin)'],
];

$passed = 0;
$failed = 0;

foreach ($testCases as $index => $test) {
    $result = validateScheduleTime($schedule, $test['day'], $test['time'], $tolerance);
    $status = $result['valid'] === $test['expected'] ? '✅ PASS' : '❌ FAIL';
    
    if ($result['valid'] === $test['expected']) {
        $passed++;
    } else {
        $failed++;
    }
    
    echo sprintf(
        "%s Test #%d: %s\n",
        $status,
        $index + 1,
        $test['desc']
    );
    
    echo sprintf(
        "   Request: %s %s | Expected: %s | Got: %s\n",
        getDayName($test['day']),
        $test['time'],
        $test['expected'] ? 'VALID' : 'INVALID',
        $result['valid'] ? 'VALID' : 'INVALID'
    );
    
    if (!$result['valid']) {
        echo "   Reason: {$result['message']}\n";
        if (isset($result['error']['code'])) {
            echo "   Error Code: {$result['error']['code']}\n";
        }
    }
    
    echo "\n";
}

echo "=== SUMMARY ===\n";
echo "Total Tests: " . count($testCases) . "\n";
echo "Passed: {$passed} ✅\n";
echo "Failed: {$failed} " . ($failed > 0 ? '❌' : '✅') . "\n";
echo "\nResult: " . ($failed === 0 ? 'ALL TESTS PASSED ✅' : 'SOME TESTS FAILED ❌') . "\n";

// Helper functions
function validateScheduleTime($schedule, $currentDay, $currentTime, $tolerance)
{
    $timezone = $schedule->timezone ?? 'Asia/Jakarta';
    
    // Validate day first (simple integer comparison)
    if ((int)$schedule->day_of_week !== (int)$currentDay) {
        return [
            'valid' => false,
            'message' => 'QR code hanya dapat dibuat pada hari jadwal mengajar.',
            'error' => [
                'code' => 'INVALID_DAY',
                'expected_day' => getDayName($schedule->day_of_week),
                'current_day' => getDayName($currentDay),
            ],
        ];
    }

    // Create Carbon instances for time comparison
    // Use a fixed date (doesn't matter which date, we only care about time)
    $baseDate = '2026-02-10'; // Monday
    
    $now = Carbon::parse("{$baseDate} {$currentTime}", $timezone);
    
    $start = Carbon::parse("{$baseDate} {$schedule->start_time}", $timezone)
        ->subMinutes($tolerance);
    
    $end = Carbon::parse("{$baseDate} {$schedule->end_time}", $timezone)
        ->addMinutes($tolerance);

    if (!$now->betweenIncluded($start, $end)) {
        return [
            'valid' => false,
            'message' => 'QR code hanya dapat dibuat dalam rentang waktu jadwal mengajar.',
            'error' => [
                'code' => 'INVALID_TIME',
                'schedule_time' => "{$schedule->start_time} - {$schedule->end_time}",
                'current_time' => $currentTime,
                'allowed_time_range' => "{$start->format('H:i:s')} - {$end->format('H:i:s')}",
            ],
        ];
    }

    return [
        'valid' => true,
    ];
}

function getDayName($dayOfWeek)
{
    $days = [
        0 => 'Minggu',
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
    ];

    return $days[$dayOfWeek] ?? 'Unknown';
}
