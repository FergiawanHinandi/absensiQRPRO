<?php

/**
 * Test Create School API
 * 
 * Usage: php test_create_school.php
 */

// Get superadmin token first
$loginUrl = 'http://localhost:8000/api/v1/auth/login';
$loginData = [
    'username' => 'superadmin',
    'password' => 'password123',
];

$ch = curl_init($loginUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($loginData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
]);

$loginResponse = curl_exec($ch);
$loginHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "=== LOGIN TEST ===\n";
echo "HTTP Code: $loginHttpCode\n";
echo "Response: $loginResponse\n\n";

if ($loginHttpCode !== 200) {
    echo "❌ Login failed!\n";
    exit(1);
}

$loginData = json_decode($loginResponse, true);
if (!isset($loginData['data']['access_token'])) {
    echo "❌ No token in response!\n";
    exit(1);
}

$token = $loginData['data']['access_token'];
echo "✅ Token obtained: " . substr($token, 0, 20) . "...\n\n";

// Test create school
$createUrl = 'http://localhost:8000/api/v1/super-admin/schools';
$schoolData = [
    'name' => 'UPT SPF SD NEGERI UNGGULAN MONGISIDI 1',
    'npsn' => '40313912',
    'school_level' => 'SD',
    'email' => 'armhyer21091993@gmail.com',
    'phone' => '082352538105',
    'address' => 'Jln. R.W. Monginsidi No.13',
];

$ch = curl_init($createUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($schoolData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
    'Authorization: Bearer ' . $token,
]);

$createResponse = curl_exec($ch);
$createHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "=== CREATE SCHOOL TEST ===\n";
echo "HTTP Code: $createHttpCode\n";
echo "Response: $createResponse\n\n";

if ($createHttpCode === 200 || $createHttpCode === 201) {
    echo "✅ School created successfully!\n";
} else {
    echo "❌ Failed to create school!\n";
    
    // Parse error
    $errorData = json_decode($createResponse, true);
    if (isset($errorData['errors'])) {
        echo "\nValidation Errors:\n";
        foreach ($errorData['errors'] as $field => $messages) {
            echo "  - $field: " . implode(', ', $messages) . "\n";
        }
    }
}
