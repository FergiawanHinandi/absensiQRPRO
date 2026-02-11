<?php

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use Ramsey\Uuid\Uuid;

// CONFIGURATION
$baseUrl = 'http://localhost:8000/api/v1/';
$token = 'YOUR_TEACHER_TOKEN_HERE'; // GANTI DENGAN TOKEN VALID
$studentId = 1; // ID Siswa yang akan di-spam
$scheduleId = 1; // ID Jadwal aktif
$concurrentRequests = 30;

$client = new Client([
    'base_uri' => $baseUrl,
    'timeout'  => 10.0,
    'http_errors' => false, // Capture all responses
]);

echo "=== RACE CONDITION STRESS TEST ===\n";
echo "Target: Sending $concurrentRequests parallel check-ins for Student #$studentId\n";
echo "Strategy: Unique Idempotency Key per request (Bypassing Middleware Cache)\n\n";

// 1. Prepare Promises
$promises = [];
$startTime = microtime(true);

for ($i = 0; $i < $concurrentRequests; $i++) {
    $payload = [
        'student_id' => $studentId,
        'schedule_id' => $scheduleId,
        'attendance_date' => date('Y-m-d'),
        'status' => 'present',
        'notes' => "Race test request #$i"
    ];

    // CRITICAL: Different Key for every request to hit the DB lock
    $uniqueKey = Uuid::uuid4()->toString();

    $promises[$i] = $client->postAsync('attendance/manual', [
        'json' => $payload,
        'headers' => [
            'Authorization' => "Bearer $token",
            'X-Idempotency-Key' => $uniqueKey,
            'Accept' => 'application/json'
        ]
    ]);
}

// 2. Execute Parallel
echo "🚀 Sending requests...\n";
$results = Promise\Utils::settle($promises)->wait();
$duration = round((microtime(true) - $startTime) * 1000, 2);

echo "✅ Finished in {$duration}ms\n\n";

// 3. Analyze Results
$success = 0;
$conflicts = 0; // 409/422
$errors = 0;    // 500
$other = 0;

foreach ($results as $index => $res) {
    if ($res['state'] === 'fulfilled') {
        $code = $res['value']->getStatusCode();
        
        if ($code === 201 || $code === 200) {
            $success++;
            echo "Request #$index: 🟢 SUCCESS ($code)\n";
        } elseif ($code === 409 || $code === 422) {
            $conflicts++;
            // echo "Request #$index: 🟡 BLOCKED ($code)\n"; // Uncomment for verbose
        } elseif ($code >= 500) {
            $errors++;
            echo "Request #$index: 🔴 SERVER ERROR ($code)\n";
        } else {
            $other++;
            echo "Request #$index: ⚪ OTHER ($code)\n";
        }
    } else {
        $errors++;
        echo "Request #$index: 🔴 NETWORK ERROR\n";
    }
}

echo "\n=== REPORT ===\n";
echo "Total Requests: $concurrentRequests\n";
echo "Success (Created): $success \t(Target: 1)\n";
echo "Blocked (Conflict): $conflicts \t(Target: " . ($concurrentRequests - 1) . ")\n";
echo "Server Errors:      $errors \t(Target: 0)\n";
echo "Other:              $other\n";

// 4. Final Verdict
echo "\n=== VERDICT ===\n";
if ($success === 1 && $conflicts === ($concurrentRequests - 1)) {
    echo "✅ PASS: Race condition prevented securely.\n";
    echo "   Database constraint / Atomic lock worked perfectly.\n";
} elseif ($success > 1) {
    echo "❌ FAIL: Double spending detected! $success records created.\n";
    echo "   User was checked in multiple times simultaneously.\n";
} elseif ($errors > 0) {
    echo "⚠️  WARNING: System handled load but returned Server Errors (Deadlock?).\n";
} else {
    echo "❓ INCONCLUSIVE: Check output details.\n";
}
