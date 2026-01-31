<?php

namespace Database\Seeders;

use App\Models\School;
use Illuminate\Database\Seeder;

class SchoolSeeder extends Seeder
{
    public function run(): void
    {
        School::create([
            'name' => 'SMP Negeri 1 Jakarta',
            'npsn' => '20104001',
            'school_level' => 'SMP',
            'address' => 'Jl. Sudirman No. 123, Jakarta Pusat',
            'phone' => '021-12345678',
            'email' => 'smpn1jakarta@example.com',
            'latitude' => -6.200000,
            'longitude' => 106.816666,
            'radius_meters' => 100,
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
            'settings' => json_encode([
                'attendance' => [
                    'qr_expiry_minutes' => 10,
                    'allow_manual_input' => true,
                    'late_threshold_minutes' => 15,
                    'location_required' => true,
                ],
                'notifications' => [
                    'push_enabled' => true,
                    'email_enabled' => false,
                ],
            ]),
        ]);

        School::create([
            'name' => 'SMA Negeri 1 Bandung',
            'npsn' => '20105001',
            'school_level' => 'SMA',
            'address' => 'Jl. Asia Afrika No. 45, Bandung',
            'phone' => '022-87654321',
            'email' => 'sman1bandung@example.com',
            'latitude' => -6.921667,
            'longitude' => 107.607222,
            'radius_meters' => 150,
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
            'settings' => json_encode([
                'attendance' => [
                    'qr_expiry_minutes' => 15,
                    'allow_manual_input' => true,
                    'late_threshold_minutes' => 10,
                    'location_required' => true,
                ],
                'notifications' => [
                    'push_enabled' => true,
                    'email_enabled' => true,
                ],
            ]),
        ]);

        $this->command->info('Schools seeded successfully!');
    }
}
