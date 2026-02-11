<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Http\Kernel');

echo "=== TESTING LOGIN API ===\n\n";

// Create a test request with JSON content
$content = json_encode([
    'username' => 'superadmin',
    'password' => 'password123'
]);

$request = Illuminate\Http\Request::create(
    '/api/v1/auth/login',
    'POST',
    [],
    [],
    [],
    [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json'
    ],
    $content
);

try {
    $response = $kernel->handle($request);
    
    echo "Status Code: " . $response->getStatusCode() . "\n";
    echo "Response:\n";
    $responseContent = $response->getContent();
    
    // Pretty print JSON
    $data = json_decode($responseContent, true);
    if ($data) {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
        
        if (isset($data['success']) && $data['success']) {
            echo "✓ LOGIN SUCCESS!\n";
            echo "  Token: " . substr($data['data']['access_token'], 0, 30) . "...\n";
            echo "  User: " . $data['data']['user']['name'] . "\n";
            echo "  Role: " . $data['data']['user']['role_type'] . "\n";
        } else {
            echo "✗ LOGIN FAILED\n";
            if (isset($data['message'])) {
                echo "  Error: " . $data['message'] . "\n";
            }
        }
    } else {
        echo $responseContent . "\n";
    }
    
} catch (Exception $e) {
    echo "✗ EXCEPTION: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "  Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== TEST COMPLETE ===\n";
