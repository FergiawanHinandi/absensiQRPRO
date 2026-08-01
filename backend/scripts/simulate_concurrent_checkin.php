#!/usr/bin/env php
<?php

/**
 * Concurrent Check-In Simulator
 *
 * This script simulates 20 concurrent check-in requests to test race condition protection.
 *
 * USAGE:
 *   php simulate_concurrent_checkin.php
 *
 * REQUIREMENTS:
 *   - PHP 8.1+
 *   - cURL extension
 *   - Application running on localhost:8000
 *
 * EXPECTED RESULTS:
 *   - 1 request: 201 Created
 *   - 19 requests: 409 Conflict or 422 Validation Error
 */

// Configuration
$baseUrl = 'http://localhost:8000/api/v1';
$totalRequests = 20;

// Test data
$studentId = 1; // Change this to actual student ID
$scheduleId = 1; // Change this to actual schedule ID
$teacherToken = 'your-teacher-jwt-token'; // Change this to actual token

// Check-in data
$checkInData = [
    'student_id' => $studentId,
    'schedule_id' => $scheduleId,
    'attendance_date' => \App\Helpers\TimezoneHelper::now()->toDateString(),
    'check_in_time' => \App\Helpers\TimezoneHelper::now()->toDateTimeString(),
    'lat_in' => -6.200000,
    'lng_in' => 106.816666,
    'device_id_in' => 'test-device-' . uniqid(),
];

echo "=== Concurrent Check-In Simulator ===\n";
echo "Base URL: $baseUrl\n";
echo "Total Requests: $totalRequests\n";
echo "Student ID: $studentId\n";
echo "Schedule ID: $scheduleId\n";
echo "\n";

// Results tracking
$results = [
    'success' => 0,
    'conflict' => 0,
    'validation_error' => 0,
    'other_error' => 0,
    'details' => [],
];

// Create multi-handle for concurrent requests
$multiHandle = curl_multi_init();
$handles = [];

// Prepare all requests
for ($i = 0; $i < $totalRequests; $i++) {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => "$baseUrl/attendances/check-in",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($checkInData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            "Authorization: Bearer $teacherToken",
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    
    curl_multi_add_handle($multiHandle, $ch);
    $handles[] = $ch;
}

echo "Sending $totalRequests concurrent requests...\n";

// Execute all requests concurrently
$running = null;
do {
    curl_multi_exec($multiHandle, $running);
    curl_multi_select($multiHandle);
} while ($running > 0);

echo "All requests completed.\n\n";

// Process results
foreach ($handles as $i => $ch) {
    $response = curl_multi_getcontent($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $responseData = json_decode($response, true);
    
    $result = [
        'request' => $i + 1,
        'status' => $httpCode,
        'response' => $responseData,
    ];
    
    // Categorize result
    if ($httpCode === 201) {
        $results['success']++;
        echo "✅ Request #" . ($i + 1) . ": SUCCESS (201 Created)\n";
    } elseif ($httpCode === 409) {
        $results['conflict']++;
        echo "⚠️  Request #" . ($i + 1) . ": CONFLICT (409)\n";
    } elseif ($httpCode === 422) {
        $results['validation_error']++;
        echo "⚠️  Request #" . ($i + 1) . ": VALIDATION ERROR (422)\n";
    } else {
        $results['other_error']++;
        echo "❌ Request #" . ($i + 1) . ": ERROR ($httpCode)\n";
    }
    
    $results['details'][] = $result;
    
    curl_multi_remove_handle($multiHandle, $ch);
    curl_close($ch);
}

curl_multi_close($multiHandle);

// Summary
echo "\n=== SUMMARY ===\n";
echo "Total Requests: $totalRequests\n";
echo "✅ Success (201): " . $results['success'] . "\n";
echo "⚠️  Conflict (409): " . $results['conflict'] . "\n";
echo "⚠️  Validation Error (422): " . $results['validation_error'] . "\n";
echo "❌ Other Errors: " . $results['other_error'] . "\n";
echo "\n";

// Validation
$expectedSuccess = 1;
$expectedFailures = $totalRequests - 1;
$actualFailures = $results['conflict'] + $results['validation_error'];

if ($results['success'] === $expectedSuccess && $actualFailures === $expectedFailures) {
    echo "✅ TEST PASSED!\n";
    echo "   - Exactly 1 request succeeded\n";
    echo "   - Exactly " . $expectedFailures . " requests failed\n";
    echo "   - Race condition protection is working correctly!\n";
    exit(0);
} else {
    echo "❌ TEST FAILED!\n";
    echo "   - Expected 1 success, got " . $results['success'] . "\n";
    echo "   - Expected " . $expectedFailures . " failures, got " . $actualFailures . "\n";
    echo "   - Race condition protection may not be working correctly!\n";
    exit(1);
}
