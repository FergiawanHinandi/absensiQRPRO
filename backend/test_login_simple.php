<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

$kernel->bootstrap();

try {
    // Test login dengan password benar
    $request = Illuminate\Http\Request::create('/api/v1/auth/login', 'POST', [
        'username' => 'superadmin',
        'password' => 'admin123',
    ]);

    $response = $app->handle($request);

    echo 'Status: '.$response->getStatusCode()."\n";

    $content = $response->getContent();
    $data = json_decode($content, true);

    if ($response->getStatusCode() === 200 && isset($data['success']) && $data['success']) {
        echo "✅ LOGIN BERHASIL!\n";
        echo 'Access Token: '.substr($data['data']['access_token'], 0, 50)."...\n";
        echo 'User: '.$data['data']['user']['name'].' ('.$data['data']['user']['role_type'].")\n";
    } else {
        echo "❌ LOGIN GAGAL\n";
        echo 'Response: '.$content."\n";
    }

} catch (\Exception $e) {
    echo 'Error: '.$e->getMessage()."\n";
    echo 'File: '.$e->getFile().':'.$e->getLine()."\n";
}
