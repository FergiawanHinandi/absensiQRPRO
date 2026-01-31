<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

$kernel->bootstrap();

try {
    // Test sederhana tanpa middleware
    $request = Illuminate\Http\Request::create('/api/v1/test', 'GET');
    
    $response = $app->handle($request);
    
    echo "Status: " . $response->getStatusCode() . "\n";
    echo "Content: " . $response->getContent() . "\n";
    
} catch(\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
