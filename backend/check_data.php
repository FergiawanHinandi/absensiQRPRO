<?php

use App\Models\Attendance;
use App\Models\User;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$count = Attendance::count();
echo 'TOTAL_ATTENDANCE: '.$count."\n";

$users = User::where('role_type', 'student')->count();
echo 'TOTAL_STUDENTS: '.$users."\n";
