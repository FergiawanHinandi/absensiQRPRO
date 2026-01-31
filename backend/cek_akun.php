<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

$kernel->bootstrap();

try {
    $users = \App\Models\User::with('school')->get();
    
    echo "=== DAFTAR SEMUA AKUN TERDAFTAR ===\n\n";
    
    if ($users->isEmpty()) {
        echo "Tidak ada user yang terdaftar.\n";
    } else {
        foreach ($users as $user) {
            echo "ID: " . $user->id . "\n";
            echo "Nama: " . $user->name . "\n";
            echo "Username: " . $user->username . "\n";
            echo "Email: " . $user->email . "\n";
            echo "Role: " . $user->role_type . "\n";
            echo "Status: " . ($user->is_active ? 'Aktif' : 'Tidak Aktif') . "\n";
            echo "School ID: " . ($user->school_id ?: 'Tidak ada') . "\n";
            
            if ($user->school) {
                echo "School: " . $user->school->name . "\n";
            }
            
            echo "Created: " . $user->created_at . "\n";
            echo "Last Login: " . ($user->last_login_at ?: 'Belum pernah login') . "\n";
            echo str_repeat("-", 50) . "\n";
        }
    }
    
    echo "\n=== STATISTIK ===\n";
    echo "Total Users: " . $users->count() . "\n";
    echo "Super Admin: " . $users->where('role_type', 'super_admin')->count() . "\n";
    echo "School Admin: " . $users->where('role_type', 'school_admin')->count() . "\n";
    echo "Admin: " . $users->where('role_type', 'admin')->count() . "\n";
    echo "Teacher: " . $users->where('role_type', 'teacher')->count() . "\n";
    echo "Student: " . $users->where('role_type', 'student')->count() . "\n";
    echo "Aktif: " . $users->where('is_active', true)->count() . "\n";
    echo "Tidak Aktif: " . $users->where('is_active', false)->count() . "\n";
    
} catch(\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
