<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

$kernel->bootstrap();

try {
    $request = Illuminate\Http\Request::create('/api/v1/auth/login', 'POST', [
        'username' => 'superadmin',
        'password' => 'admin123',
    ]);

    $response = $app->handle($request);

    echo 'Status: '.$response->getStatusCode()."\n";

} catch (\Exception $e) {
    echo 'Error: '.$e->getMessage()."\n";
    echo 'File: '.$e->getFile().':'.$e->getLine()."\n";
}
