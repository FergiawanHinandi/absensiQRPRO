<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

$kernel->bootstrap();

try {
    $user = \App\Models\User::where('username', 'superadmin')->first();
    
    if ($user) {
        echo "User superadmin found:\n";
        echo "ID: " . $user->id . "\n";
        echo "Email: " . $user->email . "\n";
        echo "Role: " . $user->role_type . "\n";
        echo "Active: " . ($user->is_active ? 'Yes' : 'No') . "\n";
        echo "School ID: " . $user->school_id . "\n";
    } else {
        echo "User superadmin not found\n";
    }
    
    // Check all users
    $allUsers = \App\Models\User::count();
    echo "Total users: " . $allUsers . "\n";
    
} catch(\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
