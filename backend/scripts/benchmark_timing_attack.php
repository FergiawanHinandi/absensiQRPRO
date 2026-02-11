#!/usr/bin/env php
<?php

/**
 * Timing Attack Benchmark
 *
 * This script measures and compares response times for login attempts
 * to detect potential timing attack vulnerabilities.
 *
 * USAGE:
 *   php benchmark_timing_attack.php
 *
 * REQUIREMENTS:
 *   - PHP 8.1+
 *   - cURL extension
 *   - Application running on localhost:8000
 *
 * EXPECTED RESULTS:
 *   - Similar response times for valid/invalid emails
 *   - Difference should be < 20%
 */

// Configuration
$baseUrl = 'http://localhost:8000/api/v1';
$iterations = 30; // Number of samples per test

// Test data
$validEmail = 'teacher@example.com'; // Change to actual email
$invalidEmail = 'nonexistent@example.com';
$wrongPassword = 'wrong-password-123';

echo "=== Timing Attack Benchmark ===\n";
echo "Base URL: $baseUrl\n";
echo "Iterations: $iterations per test\n";
echo "\n";

// Results storage
$validEmailTimes = [];
$invalidEmailTimes = [];

// Test 1: Valid email + wrong password
echo "Test 1: Valid email + wrong password\n";
echo str_repeat('-', 50) . "\n";

for ($i = 0; $i < $iterations; $i++) {
    $start = hrtime(true);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "$baseUrl/auth/login",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'email' => $validEmail,
            'password' => $wrongPassword . $i,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $end = hrtime(true);
    $duration = ($end - $start) / 1_000_000; // Convert to milliseconds
    
    $validEmailTimes[] = $duration;
    
    echo sprintf("  Iteration %2d: %.2f ms (HTTP %d)\n", $i + 1, $duration, $httpCode);
    
    usleep(50000); // 50ms delay between requests
}

echo "\n";

// Test 2: Invalid email
echo "Test 2: Invalid email (non-existent)\n";
echo str_repeat('-', 50) . "\n";

for ($i = 0; $i < $iterations; $i++) {
    $start = hrtime(true);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "$baseUrl/auth/login",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'email' => 'invalid' . $i . '@example.com',
            'password' => 'any-password' . $i,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $end = hrtime(true);
    $duration = ($end - $start) / 1_000_000;
    
    $invalidEmailTimes[] = $duration;
    
    echo sprintf("  Iteration %2d: %.2f ms (HTTP %d)\n", $i + 1, $duration, $httpCode);
    
    usleep(50000);
}

echo "\n";

// Calculate statistics
function calculateStats(array $times): array
{
    $count = count($times);
    $sum = array_sum($times);
    $mean = $sum / $count;
    
    $min = min($times);
    $max = max($times);
    
    // Standard deviation
    $variance = array_sum(array_map(function ($x) use ($mean) {
        return pow($x - $mean, 2);
    }, $times)) / $count;
    $stdDev = sqrt($variance);
    
    // Median
    sort($times);
    $middle = floor($count / 2);
    $median = $count % 2 === 0
        ? ($times[$middle - 1] + $times[$middle]) / 2
        : $times[$middle];
    
    return [
        'count' => $count,
        'mean' => $mean,
        'median' => $median,
        'min' => $min,
        'max' => $max,
        'stdDev' => $stdDev,
        'range' => $max - $min,
    ];
}

$validStats = calculateStats($validEmailTimes);
$invalidStats = calculateStats($invalidEmailTimes);

// Display statistics
echo "=== STATISTICS ===\n";
echo str_repeat('=', 70) . "\n\n";

echo "Valid Email + Wrong Password:\n";
echo sprintf("  Samples:         %d\n", $validStats['count']);
echo sprintf("  Mean:            %.2f ms\n", $validStats['mean']);
echo sprintf("  Median:          %.2f ms\n", $validStats['median']);
echo sprintf("  Min:             %.2f ms\n", $validStats['min']);
echo sprintf("  Max:             %.2f ms\n", $validStats['max']);
echo sprintf("  Std Deviation:   %.2f ms\n", $validStats['stdDev']);
echo sprintf("  Range:           %.2f ms\n", $validStats['range']);
echo "\n";

echo "Invalid Email:\n";
echo sprintf("  Samples:         %d\n", $invalidStats['count']);
echo sprintf("  Mean:            %.2f ms\n", $invalidStats['mean']);
echo sprintf("  Median:          %.2f ms\n", $invalidStats['median']);
echo sprintf("  Min:             %.2f ms\n", $invalidStats['min']);
echo sprintf("  Max:             %.2f ms\n", $invalidStats['max']);
echo sprintf("  Std Deviation:   %.2f ms\n", $invalidStats['stdDev']);
echo sprintf("  Range:           %.2f ms\n", $invalidStats['range']);
echo "\n";

// Compare results
$meanDifference = abs($validStats['mean'] - $invalidStats['mean']);
$percentDifference = ($meanDifference / max($validStats['mean'], $invalidStats['mean'])) * 100;

echo "=== COMPARISON ===\n";
echo str_repeat('=', 70) . "\n\n";

echo sprintf("Mean Difference:     %.2f ms\n", $meanDifference);
echo sprintf("Percent Difference:  %.2f%%\n", $percentDifference);
echo "\n";

// Visualization
echo "=== DISTRIBUTION ===\n";
echo str_repeat('=', 70) . "\n\n";

echo "Valid Email Times:\n";
echo "  " . createHistogram($validEmailTimes, 10) . "\n\n";

echo "Invalid Email Times:\n";
echo "  " . createHistogram($invalidEmailTimes, 10) . "\n\n";

// Verdict
echo "=== VERDICT ===\n";
echo str_repeat('=', 70) . "\n\n";

$maxAllowedDifference = 20; // percent

if ($percentDifference <= $maxAllowedDifference) {
    echo "✅ TEST PASSED!\n\n";
    echo "Response times are similar:\n";
    echo sprintf("  - Valid email:   %.2f ms (±%.2f ms)\n", $validStats['mean'], $validStats['stdDev']);
    echo sprintf("  - Invalid email: %.2f ms (±%.2f ms)\n", $invalidStats['mean'], $invalidStats['stdDev']);
    echo sprintf("  - Difference:    %.2f%% (allowed: %.2f%%)\n", $percentDifference, $maxAllowedDifference);
    echo "\n";
    echo "✅ Timing attack protection is working correctly!\n";
    echo "✅ No significant timing difference detected\n";
    echo "✅ Constant-time comparison appears to be implemented\n";
    exit(0);
} else {
    echo "⚠️  WARNING: Significant timing difference detected!\n\n";
    echo "Response times differ significantly:\n";
    echo sprintf("  - Valid email:   %.2f ms (±%.2f ms)\n", $validStats['mean'], $validStats['stdDev']);
    echo sprintf("  - Invalid email: %.2f ms (±%.2f ms)\n", $invalidStats['mean'], $invalidStats['stdDev']);
    echo sprintf("  - Difference:    %.2f%% (allowed: %.2f%%)\n", $percentDifference, $maxAllowedDifference);
    echo "\n";
    echo "❌ This may indicate a timing attack vulnerability!\n";
    echo "❌ Attackers could use timing to enumerate valid emails\n";
    echo "\n";
    echo "🔍 RECOMMENDATIONS:\n";
    echo "  1. Ensure Hash::check() is called for both valid and invalid users\n";
    echo "  2. Use a dummy hash for non-existent users\n";
    echo "  3. Enforce minimum response time (100ms+)\n";
    echo "  4. Add random jitter (0-10ms)\n";
    echo "  5. Review AuthController::login() implementation\n";
    exit(1);
}

/**
 * Create simple histogram
 */
function createHistogram(array $values, int $bins = 10): string
{
    $min = min($values);
    $max = max($values);
    $range = $max - $min;
    $binSize = $range / $bins;
    
    $histogram = array_fill(0, $bins, 0);
    
    foreach ($values as $value) {
        $bin = min($bins - 1, (int)(($value - $min) / $binSize));
        $histogram[$bin]++;
    }
    
    $maxCount = max($histogram);
    $output = '';
    
    for ($i = 0; $i < $bins; $i++) {
        $binStart = $min + ($i * $binSize);
        $binEnd = $binStart + $binSize;
        $count = $histogram[$i];
        $barLength = $maxCount > 0 ? (int)(($count / $maxCount) * 40) : 0;
        $bar = str_repeat('█', $barLength);
        
        $output .= sprintf(
            "  %.1f-%.1f ms: %s (%d)\n",
            $binStart,
            $binEnd,
            $bar,
            $count
        );
    }
    
    return $output;
}
