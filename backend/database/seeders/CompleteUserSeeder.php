<?php

namespace Database\Seeders;

use App\Models\School;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class CompleteUserSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::first();

        if (! $school) {
            $this->command->error('No school found. Please run SchoolSeeder first.');

            return;
        }

        // Ensure roles exist
        $roleNames = ['super_admin', 'school_admin', 'principal', 'teacher', 'homeroom_teacher', 'student', 'parent'];
        foreach ($roleNames as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'sanctum']);
        }

        $users = [
            // Super Admin (Pemilik Platform)
            [
                'school_id' => null,
                'name' => 'Super Administrator',
                'username' => 'superadmin',
                'email' => 'superadmin@absensiQR.com',
                'password' => Hash::make('password'),
                'role_type' => 'super_admin',
                'is_active' => true,
                'spatie_role' => 'super_admin',
            ],

            // Admin Sekolah (School Admin)
            [
                'school_id' => $school->id,
                'name' => 'Admin Sekolah SMP 1',
                'username' => 'adminsekolah',
                'email' => 'adminsekolah@smp1.com',
                'password' => Hash::make('password'),
                'role_type' => 'school_admin',
                'is_active' => true,
                'spatie_role' => 'school_admin',
            ],

            // Kepala Sekolah (Principal)
            [
                'school_id' => $school->id,
                'name' => 'Drs. Budi Santoso, M.Pd',
                'username' => 'kepala.sekolah',
                'email' => 'kepsek@smp1.com',
                'password' => Hash::make('password'),
                'role_type' => 'principal',
                'is_active' => true,
                'spatie_role' => 'principal',
            ],

            // Guru Mapel (Subject Teacher) - HANYA role 'teacher'
            [
                'school_id' => $school->id,
                'name' => 'Siti Nurhaliza, S.Pd',
                'username' => 'guru.matematika',
                'email' => 'guru.mtk@smp1.com',
                'password' => Hash::make('password'),
                'role_type' => 'teacher',
                'is_active' => true,
                'spatie_role' => 'teacher',
            ],
            [
                'school_id' => $school->id,
                'name' => 'Ahmad Fauzi, S.Pd',
                'username' => 'guru.ipa',
                'email' => 'guru.ipa@smp1.com',
                'password' => Hash::make('password'),
                'role_type' => 'teacher',
                'is_active' => true,
                'spatie_role' => 'teacher',
            ],

            // Guru Wali Kelas - TETAP role 'teacher', tapi dengan konfigurasi
            [
                'school_id' => $school->id,
                'name' => 'Rina Wati, S.Pd',
                'username' => 'wali.7a',
                'email' => 'wali.7a@smp1.com',
                'password' => Hash::make('password'),
                'role_type' => 'teacher',
                'is_active' => true,
                'spatie_role' => 'teacher',
            ],

            // Siswa
            [
                'school_id' => $school->id,
                'name' => 'Ahmad Rizki',
                'username' => 'siswa001',
                'email' => 'siswa001@smp1.com',
                'password' => Hash::make('password'),
                'role_type' => 'student',
                'is_active' => true,
                'spatie_role' => 'student',
            ],
            [
                'school_id' => $school->id,
                'name' => 'Siti Aisyah',
                'username' => 'siswa002',
                'email' => 'siswa002@smp1.com',
                'password' => Hash::make('password'),
                'role_type' => 'student',
                'is_active' => true,
                'spatie_role' => 'student',
            ],
            [
                'school_id' => $school->id,
                'name' => 'Budi Santoso',
                'username' => 'siswa003',
                'email' => 'siswa003@smp1.com',
                'password' => Hash::make('password'),
                'role_type' => 'student',
                'is_active' => true,
                'spatie_role' => 'student',
            ],

            // Orang Tua
            [
                'school_id' => $school->id,
                'name' => 'Bapak Ahmad (Orang Tua Rizki)',
                'username' => 'ortu.rizki',
                'email' => 'ortu.rizki@gmail.com',
                'password' => Hash::make('password'),
                'role_type' => 'parent',
                'is_active' => true,
                'spatie_role' => 'parent',
            ],
            [
                'school_id' => $school->id,
                'name' => 'Ibu Siti (Orang Tua Aisyah)',
                'username' => 'ortu.aisyah',
                'email' => 'ortu.aisyah@gmail.com',
                'password' => Hash::make('password'),
                'role_type' => 'parent',
                'is_active' => true,
                'spatie_role' => 'parent',
            ],
        ];

        foreach ($users as $userData) {
            $spatieRole = $userData['spatie_role'] ?? null;
            unset($userData['spatie_role']);

            $user = User::updateOrCreate(
                ['username' => $userData['username']],
                $userData
            );

            // Assign Spatie role
            if ($spatieRole && $user->roles->pluck('name')->first() !== $spatieRole) {
                $user->syncRoles([$spatieRole]);
            }
        }

        $this->command->info('✅ Complete user demo created successfully with Spatie roles!');
        $this->command->info('📋 Total users: '.count($users));
        $this->command->info('🔐 Default password: password');
    }
}
