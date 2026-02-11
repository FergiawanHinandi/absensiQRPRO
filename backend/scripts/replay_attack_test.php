<?php

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use Ramsey\Uuid\Uuid;

// CONFIGURATION
$baseUrl = 'http://localhost:8000/api/v1/';
$token = 'YOUR_TEACHER_TOKEN_HERE'; // GANTI DENGAN TOKEN VALID
$studentId = 2; // Pakai ID siswa berbeda dari tes sebelumnya
$scheduleId = 1;

$client = new Client([
    'base_uri' => $baseUrl,
    'timeout'  => 5.0,
    'http_errors' => false,
]);

echo "=== REPLAY ATTACK TEST ===\n";
echo "Target: Sending 10 IDENTICAL requests within 1 minute\n";
echo "Strategy: SAME Idempotency Key (Middleware Test)\n\n";

// 1. Generate ONE Key for ALL requests
$sharedKey = Uuid::uuid4()->toString();
echo "🔑 Using Key: $sharedKey\n\n";

$promises = [];
$startTime = microtime(true);

for ($i = 0; $i < 10; $i++) {
    $promises[$i] = $client->postAsync('attendance/manual', [
        'json' => [
            'student_id' => $studentId,
            'schedule_id' => $scheduleId,
            'attendance_date' => date('Y-m-d'),
            'status' => 'present',
            'notes' => 'Replay Test'
        ],
        'headers' => [
            'Authorization' => "Bearer $token",
            'X-Idempotency-Key' => $sharedKey, // SAME KEY
            'Accept' => 'application/json'
        ]
    ]);
    
    // Slight delay to simulate natural replay, but fast enough to fit in 1 min
    usleep(10000); // 10ms
}

// 2. Execute
echo "🚀 Sending Replay Requests...\n";
$results = Promise\Utils::settle($promises)->wait();

// 3. Analyze
$original = 0;
$replayed = 0;
$errors = 0;

foreach ($results as $index => $res) {
    if ($res['state'] === 'fulfilled') {
        $response = $res['value'];
        $code = $response->getStatusCode();
        $isReplayHeader = $response->getHeaderLine('X-Idempotency-Replay');
        
        if ($code === 201 || ($code === 200 && empty($isReplayHeader))) {
            $original++;
            echo "Request #$index: 🟢 PROCESSED ($code)\n";
        } elseif ($code === 409 || !empty($isReplayHeader)) {
            $replayed++;
            $status = !empty($isReplayHeader) ? "CACHED RESPONSE" : "CONFLICT";
            echo "Request #$index: 🔵 REPLAY DETECTED ($code - $status)\n";
        } else {
            $errors++;
            echo "Request #$index: 🔴 ERROR ($code)\n";
        }
    }
}

echo "\n=== REPORT ===\n";
echo "Total Requests: 10\n";
echo "Processed:      $original \t(Target: 1)\n";
echo "Replayed:       $replayed \t(Target: 9)\n";

if ($original === 1 && $replayed === 9) {
    echo "\n✅ PASS: System correctly identified and handled replay attacks.\n";
} else {
    echo "\n❌ FAIL: Multiple requests processed or unexpected behavior.\n";
}
