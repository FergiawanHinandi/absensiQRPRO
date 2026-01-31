<?php

use App\Models\User;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = User::find(2);
if ($user) {
    echo 'USER_2_FOUND: '.$user->name.' ('.$user->email.', role: '.$user->role_type.")\n";
} else {
    echo "USER_2_NOT_FOUND\n";
}
