<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(0);
ini_set('memory_limit', '1G');

use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

try {
    // 1. Setup Data
    echo "Setting up data..." . PHP_EOL;
    function runId(): string { return substr(md5(uniqid('', true)), 0, 6); }

    $rid = runId();
    $school = new School([
        'name' => 'LoadTest School '.$rid,
        'address' => 'LT Address',
        'is_active' => true,
        'settings' => [],
    ]);
    $school->npsn = (string) rand(10000000, 99999999);
    $school->school_level = 'SMA';
    $school->save();

    $teachers = [];
    $passwordHash = Hash::make('password');
    // Create 50 teachers
    for ($i = 1; $i <= 50; $i++) {
        // echo "Creating teacher $i..." . PHP_EOL;
        try {
            $email = "lt_teacher_{$rid}_{$i}@example.test";
            $username = "lt_teacher_{$rid}_{$i}";
            $u = User::create([
                'school_id' => $school->id,
                'username' => $username,
                'name' => "LT Teacher {$rid} {$i}",
                'email' => $email,
                'password' => $passwordHash,
                'role_type' => 'teacher',
                'is_active' => true,
                'device_id' => "lt-device-{$rid}-{$i}",
            ]);
            // Create token
            $token = $u->createToken('loadtest', ['*'])->plainTextToken;
            $teachers[] = ['token' => $token, 'device_id' => "lt-device-{$rid}-{$i}"];
        } catch (\Throwable $e) {
            echo "Failed creating teacher $i: " . $e->getMessage() . PHP_EOL;
        }
    }
    echo "Created " . count($teachers) . " teachers for School ID: {$school->id}" . PHP_EOL;

} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
    exit(1);
}

// 2. Load Test Logic
$baseUrl = 'http://127.0.0.1:8010';
$rounds = 5;
$interval = 10; // seconds

echo "Starting load test: " . count($teachers) . " concurrent users, {$rounds} rounds, {$interval}s interval." . PHP_EOL;
echo "Target: Response < 800ms, No timeouts." . PHP_EOL;

$ch = curl_init($baseUrl . '/up'); // Laravel 11 uses /up or I can use /api/v1/health if exists. I'll use /
curl_setopt($ch, CURLOPT_URL, $baseUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
if ($res === false) {
    echo "Initial connectivity check failed: " . curl_error($ch) . PHP_EOL;
} else {
    echo "Initial connectivity check passed. Length: " . strlen($res) . PHP_EOL;
}
curl_close($ch);

for ($r = 1; $r <= $rounds; $r++) {
    echo "------------------------------------------------" . PHP_EOL;
    echo "Round {$r} execution..." . PHP_EOL;
    
    $mh = curl_multi_init();
    $handles = [];
    
    echo "  Preparing requests..." . PHP_EOL;
    foreach ($teachers as $i => $t) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $baseUrl . '/api/v1/teacher/today-sessions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $t['token'],
            'Accept: application/json',
            'X-Device-ID: ' . $t['device_id'],
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10s timeout
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }
    
    echo "  Executing requests..." . PHP_EOL;
    $running = null;
    $status = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh);
        }
    } while ($running > 0 && $status == CURLM_OK);

    if ($status != CURLM_OK) {
        echo "  Curl Multi Error: $status" . PHP_EOL;
    }
    
    echo "  Collecting results..." . PHP_EOL;
    // Collect results
    $durations = [];
    $errors = 0;
    $timeouts = 0;
    $non200 = 0;
    
    foreach ($handles as $i => $ch) {
        $info = curl_getinfo($ch);
        $error = curl_error($ch);
        $duration = $info['total_time'] * 1000; // ms
        $httpCode = $info['http_code'];
        
        if ($error) {
            $errors++;
            echo "Request $i Error: $error" . PHP_EOL;
        } elseif ($httpCode !== 200) {
            $non200++;
            echo "Request $i HTTP Code: $httpCode" . PHP_EOL;
        } else {
            $durations[] = $duration;
        }
        
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    
    // Stats
    if (count($durations) > 0) {
        $avg = array_sum($durations) / count($durations);
        $max = max($durations);
        sort($durations);
        $p95Index = floor(count($durations) * 0.95);
        $p95 = $durations[$p95Index];
        
        echo sprintf("Round %d Stats:\n", $r);
        echo sprintf("  - Successful: %d\n", count($durations));
        echo sprintf("  - Failed/Non-200: %d\n", $errors + $non200);
        echo sprintf("  - Avg Time: %.2f ms\n", $avg);
        echo sprintf("  - P95 Time: %.2f ms\n", $p95);
        echo sprintf("  - Max Time: %.2f ms\n", $max);
        
        if ($max > 800) {
            echo "  [FAIL] Max response time > 800ms!\n";
        } else {
            echo "  [PASS] Response time within limits.\n";
        }
    } else {
        echo "Round $r: All requests failed. Is server running?\n";
    }
    
    if ($r < $rounds) {
        echo "Waiting {$interval}s..." . PHP_EOL;
        sleep($interval);
    }
}
echo "Load test completed." . PHP_EOL;
