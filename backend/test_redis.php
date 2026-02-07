<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

$kernel->bootstrap();

try {
    \Illuminate\Support\Facades\Redis::connection()->ping();
    echo "Redis connected successfully\n";
} catch (\Exception $e) {
    echo 'Redis error: '.$e->getMessage()."\n";
}

try {
    \Illuminate\Support\Facades\Cache::put('test_key', 'test_value', 60);
    $value = \Illuminate\Support\Facades\Cache::get('test_key');
    echo 'Cache test: '.($value === 'test_value' ? 'SUCCESS' : 'FAILED')."\n";
} catch (\Exception $e) {
    echo 'Cache error: '.$e->getMessage()."\n";
}
