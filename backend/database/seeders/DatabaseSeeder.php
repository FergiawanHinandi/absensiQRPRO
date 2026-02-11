<?php

namespace Database\Seeders;

use App\Models\School;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Reset Cached Roles/Permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 2. Clear Tables (driver agnostic)
        Schema::disableForeignKeyConstraints();

        $tables = [
            'model_has_permissions',
            'model_has_roles',
            'permissions',
            'roles',
            'users',
            'schools',
            'classes',
            'academic_years',
            'subjects',
            'schedules',
            'attendances',
            'attendance_logs',
            'user_profiles',
            'class_students',
            'payments',
            'audit_logs',
            'announcements',
            'feature_flags',
            'schedule_templates',
        ];

        foreach ($tables as $table) {
            DB::table($table)->truncate();
        }

        Schema::enableForeignKeyConstraints();

        $password = Hash::make('password'); // Default password

        // 3. Create Roles (Using 'sanctum' guard as defined in User model)
        // Adjust guard if necessary. Usually 'web' is default, but User has $guard_name = 'sanctum'
        $roleNames = ['super_admin', 'school_admin', 'teacher', 'homeroom_teacher', 'student', 'parent'];
        foreach ($roleNames as $name) {
            Role::create(['name' => $name, 'guard_name' => 'sanctum']);
        }

        // 4. Create Super Admin
        $superAdmin = User::create([
            'name' => 'Super Administrator',
            'email' => 'super@admin.com',
            'username' => 'superadmin',
            'password' => $password,
            'role_type' => 'super_admin', // Fixed column name
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');

        // 5. Define School Levels to Generate
        $levels = ['sd', 'smp', 'sma', 'smk'];

        foreach ($levels as $level) {
            $upperLevel = strtoupper($level);

            // Create School
            $school = School::create([
                'name' => "Sekolah $upperLevel Harapan Bangsa",
                'npsn' => '100'.rand(10000, 99999),
                'school_level' => $upperLevel, // Fixed to Uppercase to satisfy Check Constraint
                'address' => "Jl. Pendidikan $upperLevel No. 123",
                'email' => "info.$level@harapanbangsa.com",
                'phone' => '021-555'.rand(1000, 9999),
                'timezone' => 'Asia/Jakarta',
                'is_active' => true,
            ]);

            // Create School Admin
            $schoolAdmin = User::create([
                'name' => "Admin $upperLevel",
                'email' => "admin.$level@demo.com",
                'username' => "admin_$level",
                'password' => $password,
                'role_type' => 'school_admin', // Fixed enum value
                'school_id' => $school->id,
                'is_active' => true,
            ]);
            $schoolAdmin->assignRole('school_admin');

            // Create Teacher (Homeroom - Wali Kelas)
            $teacher = User::create([
                'name' => "Guru $upperLevel",
                'email' => "guru.$level@demo.com",
                'username' => "guru_$level",
                'password' => $password,
                'role_type' => 'homeroom_teacher', // Fixed column name
                'school_id' => $school->id,
                'is_active' => true,
            ]);
            $teacher->assignRole('homeroom_teacher');
            $teacher->assignRole('teacher'); // Assign general teacher role too just in case

            // Create Student
            $student = User::create([
                'name' => "Siswa $upperLevel",
                'email' => "siswa.$level@demo.com",
                'username' => "siswa$level",
                'password' => $password,
                'role_type' => 'student', // Fixed column name
                'school_id' => $school->id,
                'is_active' => true,
            ]);
            $student->assignRole('student');

            // Create Parent
            $parent = User::create([
                'name' => "Orang Tua $upperLevel",
                'email' => "ortu.$level@demo.com",
                'username' => "ortu_$level",
                'password' => $password,
                'role_type' => 'parent', // Fixed column name
                'school_id' => $school->id,
                'is_active' => true,
            ]);
            $parent->assignRole('parent');
        }

        $this->command->info('Database (RE)seeded successfully with Spatie Roles and correct `role_type`.');
    }
}
