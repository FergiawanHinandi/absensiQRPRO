<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

$kernel->bootstrap();

try {
    // Test user lookup
    $user = \App\Models\User::where('username', 'superadmin')
        ->where('is_active', true)
        ->first();
    
    if ($user) {
        echo "✅ User ditemukan\n";
        echo "ID: " . $user->id . "\n";
        echo "Name: " . $user->name . "\n";
        echo "Username: " . $user->username . "\n";
        echo "Email: " . $user->email . "\n";
        echo "Role: " . $user->role_type . "\n";
        echo "Active: " . ($user->is_active ? 'Yes' : 'No') . "\n";
        
        // Test password verification
        if (\Illuminate\Support\Facades\Hash::check('admin123', $user->password)) {
            echo "✅ Password benar\n";
            
            // Test token creation
            $token = $user->createToken('test-token', ['*']);
            echo "✅ Token berhasil dibuat: " . substr($token->plainTextToken, 0, 50) . "...\n";
            
            // Revoke token
            $user->tokens()->delete();
            echo "✅ Token dibatalkan\n";
            
        } else {
            echo "❌ Password salah\n";
        }
    } else {
        echo "❌ User tidak ditemukan\n";
    }
    
} catch(\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
