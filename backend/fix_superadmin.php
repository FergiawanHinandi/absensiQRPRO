<?php

// Bootstrap Laravel
require_once __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

echo "=== FIXING SUPERADMIN LOGIN ===\n";

// Check if superadmin exists
$superadmin = User::where('username', 'superadmin')->first();

if (! $superadmin) {
    echo "❌ Superadmin not found. Creating new superadmin...\n";

    $superadmin = User::create([
        'username' => 'superadmin',
        'name' => 'Super Administrator',
        'email' => 'superadmin@absensiqr.com',
        'password' => Hash::make('password123'),
        'role_type' => 'super_admin',
        'is_active' => true,
        'school_id' => null,
    ]);

    echo "✅ Superadmin created successfully!\n";
} else {
    echo "✅ Superadmin found. Updating password...\n";

    $superadmin->update([
        'password' => Hash::make('password123'),
        'is_active' => true,
    ]);

    echo "✅ Password updated successfully!\n";
}

// Verify password
$isValid = Hash::check('password123', $superadmin->password);
echo '🔐 Password verification: '.($isValid ? '✅ VALID' : '❌ INVALID')."\n";

// Show user details
echo "\n=== USER DETAILS ===\n";
echo 'ID: '.$superadmin->id."\n";
echo 'Username: '.$superadmin->username."\n";
echo 'Email: '.$superadmin->email."\n";
echo 'Role: '.$superadmin->role_type."\n";
echo 'Active: '.($superadmin->is_active ? 'Yes' : 'No')."\n";
echo 'School ID: '.($superadmin->school_id ?? 'NULL')."\n";

echo "\n✅ SUPERADMIN LOGIN FIXED!\n";
echo "You can now login with:\n";
echo "Username: superadmin\n";
echo "Password: password123\n";
