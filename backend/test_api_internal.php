<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Models\User;
use Illuminate\Http\Request;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = User::find(2); // Admin SD
auth()->login($user);

$request = Request::create('/api/v1/reports/daily', 'GET');
$request->setUserResolver(function () use ($user) {
    return $user;
});

$controller = $app->make(AttendanceController::class);
try {
    $response = $controller->dailyReport($request);
    echo 'RESPONSE_CONTENT: '.$response->getContent()."\n";
} catch (\Exception $e) {
    echo 'ERROR: '.$e->getMessage()."\n";
    echo 'TRACE: '.$e->getTraceAsString()."\n";
}
