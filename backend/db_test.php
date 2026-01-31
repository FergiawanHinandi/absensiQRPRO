<?php

use App\Models\User;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';

// Boot the application
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$email = 'admin.sd@demo.com';
$user = User::where('email', $email)->orWhere('username', 'admin_sd')->first();

if ($user) {
    echo "USER_FOUND\n";
    echo 'Name: '.$user->name."\n";
    echo 'Email: '.$user->email."\n";
    echo 'Username: '.$user->username."\n";
    echo 'Role Type: '.$user->role_type."\n";
    echo 'Active: '.($user->is_active ? 'Yes' : 'No')."\n";
} else {
    echo "USER_NOT_FOUND\n";

    // Show some users
    echo "Recent Users:\n";
    User::limit(5)->get()->each(function ($u) {
        echo '- '.$u->username.' ('.$u->email.")\n";
    });
}
