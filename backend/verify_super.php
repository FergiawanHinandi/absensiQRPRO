<?php

use App\Models\User;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = User::where('email', 'super@admin.com')->first();
if ($user) {
    echo 'SUPER_ADMIN_FOUND: '.$user->name.' (email: '.$user->email.', role: '.$user->role_type.")\n";
    // Check password implicitly by trying to verify 'password'
    if (Illuminate\Support\Facades\Hash::check('password', $user->password)) {
        echo "PASSWORD_VERIFIED: 'password' is correct.\n";
    } else {
        echo "PASSWORD_FAILED: 'password' is incorrect.\n";
    }
} else {
    echo "SUPER_ADMIN_NOT_FOUND\n";
}
