<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

$user = User::where('username', 'superadmin')->first();
if ($user) {
    $user->update(['password' => Hash::make('password123')]);
    echo "Password updated successfully for superadmin\n";
} else {
    echo "Superadmin user not found\n";
}
