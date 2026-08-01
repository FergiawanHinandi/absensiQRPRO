<?php

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;

$client = new Client([
    'base_uri' => 'http://localhost:8000/api/v1/',
    'timeout'  => 10.0,
    'http_errors' => false // Don't throw exceptions on 4xx/5xx
]);

// Helper for timing
function time_diff($start) {
    return round((microtime(true) - $start) * 1000, 2);
}

// User credentials map (Simulated)
$teacherToken = 'ey...'; // Replace with valid token!

echo "=== DoS Simulation & Benchmark ===\n";
echo "Target: http://localhost:8000/api/v1/\n\n";

// SCENARIO 1: Distributed Load (100 distinct check-ins)
echo "[1] Distributed Load: 100 Users Check-in (Different Students)\n";
$start = microtime(true);
$promises = [];
for ($i = 0; $i < 100; $i++) {
    // Generate valid payload but distinct student
    $payload = [
        'student_id' => $i + 1, // User 1-100
        'schedule_id' => 1,
        'attendance_date' => \App\Helpers\TimezoneHelper::now()->toDateString(),
        'status' => 'present'
    ];
    
    $promises[$i] = $client->postAsync('attendance/manual', [
        'json' => $payload,
        'headers' => [
            'Authorization' => "Bearer $teacherToken",
            'X-Idempotency-Key' => \Ramsey\Uuid\Uuid::uuid4()->toString()
        ]
    ]);
}

$results1 = Promise\Utils::settle($promises)->wait();
$duration1 = time_diff($start);

// Analyze 1
$success1 = 0;
$fail1 = 0;
$errors1 = [];
foreach ($results1 as $res) {
    if ($res['state'] === 'fulfilled') {
        $code = $res['value']->getStatusCode();
        if ($code >= 200 && $code < 300) $success1++;
        else {
            $fail1++;
            $errors1[$code] = ($errors1[$code] ?? 0) + 1;
        }
    } else {
        $fail1++;
        $errors1['network'] = ($errors1['network'] ?? 0) + 1;
    }
}
echo "    Duration: {$duration1}ms\n";
echo "    Success: $success1, Fail: $fail1\n";
echo "    Errors: " . json_encode($errors1) . "\n\n";


// SCENARIO 2: Concurrency Conflict (50 attempts SAME User)
echo "[2] Concurrency Conflict: 50 Attempts SAME User (Locking Test)\n";
$studentId = 999;
$start = microtime(true);
$promises = [];
for ($i = 0; $i < 50; $i++) {
    $payload = [
        'student_id' => $studentId, 
        'schedule_id' => 1,
        'attendance_date' => \App\Helpers\TimezoneHelper::now()->toDateString(),
        'status' => 'present'
    ];
    
    // Use different Idempotency Key to simulate race condition on business logic, NOT middleware
    // Or same key to test middleware lock.
    // Let's test BUSINESS LOGIC LOCK (different keys)
    $promises[$i] = $client->postAsync('attendance/manual', [
        'json' => $payload,
        'headers' => [
            'Authorization' => "Bearer $teacherToken",
            'X-Idempotency-Key' => \Ramsey\Uuid\Uuid::uuid4()->toString()
        ]
    ]);
}

$results2 = Promise\Utils::settle($promises)->wait();
$duration2 = time_diff($start);

// Analyze 2
$success2 = 0;
$lockConflicts = 0; // 409 or similar business error
foreach ($results2 as $res) {
    if ($res['state'] === 'fulfilled') {
        $code = $res['value']->getStatusCode();
        if ($code >= 200 && $code < 300) $success2++;
        elseif ($code === 409 || $code === 422) $lockConflicts++; // Expected conflicts
        else $fail2++; // 500 or timeout
    }
}
echo "    Duration: {$duration2}ms\n";
echo "    Success: $success2 (Expected: 1)\n";
echo "    Conflicts: $lockConflicts (Expected: 49)\n";
echo "    Lock Effectiveness: " . ($success2 === 1 ? 'PASS' : 'FAIL') . "\n\n";


// SCENARIO 3: Spam Login (50 requests)
echo "[3] Spam Login: 50 Requests (Rate Limit Test)\n";
$start = microtime(true);
$promises = [];
for ($i = 0; $i < 50; $i++) {
    $promises[$i] = $client->postAsync('auth/login', [
        'json' => ['email' => 'teacher@school.com', 'password' => 'wrong']
    ]);
}

$results3 = Promise\Utils::settle($promises)->wait();
$duration3 = time_diff($start);

// Analyze 3
$rateLimited = 0;
foreach ($results3 as $res) {
    if ($res['state'] === 'fulfilled') {
        if ($res['value']->getStatusCode() === 429) $rateLimited++;
    }
}
echo "    Duration: {$duration3}ms\n";
echo "    Rate Limited (429): $rateLimited (Expected: >40)\n";
echo "    Protection: " . ($rateLimited > 40 ? 'ACTIVE' : 'WEAK') . "\n\n";

echo "=== BENCHMARK SUMMARY ===\n";
echo "Checks performed. Ensure monitoring tools verify no Crash/Deadlock.\n";
