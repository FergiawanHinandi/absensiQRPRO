#!/usr/bin/env php
<?php

/**
 * Idempotency Key Simulator
 *
 * This script simulates 5 requests with the same idempotency key to test duplicate prevention.
 *
 * USAGE:
 *   php simulate_idempotency.php
 *
 * REQUIREMENTS:
 *   - PHP 8.1+
 *   - cURL extension
 *   - Application running on localhost:8000
 *
 * EXPECTED RESULTS:
 *   - 1 request: 201 Created
 *   - 4 requests: 409 Conflict
 */

// Configuration
$baseUrl = 'http://localhost:8000/api/v1';
$totalRequests = 5;

// Test data
$studentId = 1; // Change this to actual student ID
$scheduleId = 1; // Change this to actual schedule ID
$teacherToken = 'your-teacher-jwt-token'; // Change this to actual token

// Generate a single idempotency key for all requests
$idempotencyKey = sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);

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

echo "=== Idempotency Key Simulator ===\n";
echo "Base URL: $baseUrl\n";
echo "Total Requests: $totalRequests\n";
echo "Idempotency Key: $idempotencyKey\n";
echo "Student ID: $studentId\n";
echo "Schedule ID: $scheduleId\n";
echo "\n";

// Results tracking
$results = [
    'success' => 0,
    'conflict' => 0,
    'other_error' => 0,
    'details' => [],
];

// Send 5 requests with the SAME idempotency key
for ($i = 1; $i <= $totalRequests; $i++) {
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
            "X-Idempotency-Key: $idempotencyKey", // Same key for all requests
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $responseData = json_decode($response, true);
    
    curl_close($ch);
    
    $result = [
        'request' => $i,
        'status' => $httpCode,
        'response' => $responseData,
    ];
    
    // Categorize result
    if ($httpCode === 201) {
        $results['success']++;
        echo "✅ Request #$i: SUCCESS (201 Created)\n";
        if (isset($responseData['data']['id'])) {
            echo "   Attendance ID: " . $responseData['data']['id'] . "\n";
        }
    } elseif ($httpCode === 409) {
        $results['conflict']++;
        echo "⚠️  Request #$i: CONFLICT (409)\n";
        if (isset($responseData['message'])) {
            echo "   Message: " . $responseData['message'] . "\n";
        }
    } else {
        $results['other_error']++;
        echo "❌ Request #$i: ERROR ($httpCode)\n";
        if (isset($responseData['message'])) {
            echo "   Message: " . $responseData['message'] . "\n";
        }
    }
    
    $results['details'][] = $result;
    
    // Small delay between requests
    usleep(100000); // 100ms
}

// Summary
echo "\n=== SUMMARY ===\n";
echo "Idempotency Key: $idempotencyKey\n";
echo "Total Requests: $totalRequests\n";
echo "✅ Success (201): " . $results['success'] . "\n";
echo "⚠️  Conflict (409): " . $results['conflict'] . "\n";
echo "❌ Other Errors: " . $results['other_error'] . "\n";
echo "\n";

// Validation
$expectedSuccess = 1;
$expectedConflicts = $totalRequests - 1;

if ($results['success'] === $expectedSuccess && $results['conflict'] === $expectedConflicts) {
    echo "✅ TEST PASSED!\n";
    echo "   - Exactly 1 request succeeded\n";
    echo "   - Exactly $expectedConflicts requests were rejected\n";
    echo "   - Idempotency key protection is working correctly!\n";
    echo "\n";
    echo "📊 DETAILS:\n";
    echo "   - First request: Created new attendance\n";
    echo "   - Subsequent requests: Rejected as duplicates\n";
    echo "   - Same idempotency key prevented duplicate processing\n";
    exit(0);
} else {
    echo "❌ TEST FAILED!\n";
    echo "   - Expected 1 success, got " . $results['success'] . "\n";
    echo "   - Expected $expectedConflicts conflicts, got " . $results['conflict'] . "\n";
    echo "   - Idempotency key protection may not be working correctly!\n";
    echo "\n";
    echo "🔍 DEBUGGING:\n";
    echo "   - Check if X-Idempotency-Key header is being processed\n";
    echo "   - Verify idempotency middleware is active\n";
    echo "   - Check idempotency_keys table for entries\n";
    echo "   - Review application logs for errors\n";
    exit(1);
}
