<?php

namespace Database\Seeders;

use App\Models\School;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CompleteUserSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::first();

        if (! $school) {
            $this->command->error('No school found. Please run SchoolSeeder first.');

            return;
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
            ],

            // Guru Mapel (Subject Teacher) - HANYA role 'teacher'
            [
                'school_id' => $school->id,
                'name' => 'Siti Nurhaliza, S.Pd',
                'username' => 'guru.matematika',
                'email' => 'guru.mtk@smp1.com',
                'password' => Hash::make('password'),
                'role_type' => 'teacher', // BUKAN homeroom_teacher
                'is_active' => true,
            ],
            [
                'school_id' => $school->id,
                'name' => 'Ahmad Fauzi, S.Pd',
                'username' => 'guru.ipa',
                'email' => 'guru.ipa@smp1.com',
                'password' => Hash::make('password'),
                'role_type' => 'teacher', // BUKAN homeroom_teacher
                'is_active' => true,
            ],

            // Guru Wali Kelas - TETAP role 'teacher', tapi dengan konfigurasi
            [
                'school_id' => $school->id,
                'name' => 'Rina Wati, S.Pd',
                'username' => 'wali.7a',
                'email' => 'wali.7a@smp1.com',
                'password' => Hash::make('password'),
                'role_type' => 'teacher', // BUKAN homeroom_teacher!
                'is_active' => true,
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
            ],
            [
                'school_id' => $school->id,
                'name' => 'Siti Aisyah',
                'username' => 'siswa002',
                'email' => 'siswa002@smp1.com',
                'password' => Hash::make('password'),
                'role_type' => 'student',
                'is_active' => true,
            ],
            [
                'school_id' => $school->id,
                'name' => 'Budi Santoso',
                'username' => 'siswa003',
                'email' => 'siswa003@smp1.com',
                'password' => Hash::make('password'),
                'role_type' => 'student',
                'is_active' => true,
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
            ],
            [
                'school_id' => $school->id,
                'name' => 'Ibu Siti (Orang Tua Aisyah)',
                'username' => 'ortu.aisyah',
                'email' => 'ortu.aisyah@gmail.com',
                'password' => Hash::make('password'),
                'role_type' => 'parent',
                'is_active' => true,
            ],
        ];

        foreach ($users as $userData) {
            User::updateOrCreate(
                ['username' => $userData['username']],
                $userData
            );
        }

        $this->command->info('✅ Complete user demo created successfully!');
        $this->command->info('📋 Total users: '.count($users));
        $this->command->info('⚠️  CATATAN: Semua guru menggunakan role_type = "teacher"');
        $this->command->info('⚠️  Peran wali kelas akan ditentukan via tabel teacher_roles');
        $this->command->info('⚠️  Untuk testing, gunakan admin sekolah untuk assign wali kelas');
    }
}
