<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== TESTING LOGIN CREDENTIALS ===\n\n";

// Get super admin
$superAdmin = App\Models\User::where('role_type', 'super_admin')->first();
if ($superAdmin) {
    echo "Super Admin Found:\n";
    echo "  Email: " . $superAdmin->email . "\n";
    echo "  Username: " . $superAdmin->username . "\n";
    echo "  Active: " . ($superAdmin->is_active ? 'Yes' : 'No') . "\n";
    echo "  Password Hash: " . substr($superAdmin->password, 0, 20) . "...\n\n";
}

// Get school admin
$schoolAdmin = App\Models\User::where('role_type', 'school_admin')->first();
if ($schoolAdmin) {
    echo "School Admin Found:\n";
    echo "  Email: " . $schoolAdmin->email . "\n";
    echo "  Username: " . $schoolAdmin->username . "\n";
    echo "  Active: " . ($schoolAdmin->is_active ? 'Yes' : 'No') . "\n";
    echo "  School: " . ($schoolAdmin->school ? $schoolAdmin->school->name : 'N/A') . "\n\n";
}

// Test password verification
echo "Testing password 'password123':\n";
if ($superAdmin) {
    $isValid = Illuminate\Support\Facades\Hash::check('password123', $superAdmin->password);
    echo "  Super Admin: " . ($isValid ? '✓ VALID' : '✗ INVALID') . "\n";
}

echo "\n=== TEST COMPLETE ===\n";
