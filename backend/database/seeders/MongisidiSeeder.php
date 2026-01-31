<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\School;
use App\Models\Subject;
use App\Models\TeacherRole;
use App\Models\User;
use App\Models\UserProfile;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class MongisidiSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create or Get School
        $school = School::firstOrCreate(
            ['npsn' => '12345678'], // Mock NPSN for Mongisidi
            [
                'name' => 'SD Negeri Unggulan Mongisidi 1',
                'address' => 'Jl. Mongisidi No. 1',
                'phone' => '0411-123456',
                'email' => 'admin@sdmongisidi.sch.id',
                'school_level' => 'SD',
                'is_active' => true,
                'settings' => [
                    'attendance' => [
                        'qr_expiry_minutes' => 10,
                        'late_tolerance_minutes' => 15,
                        'qr_mode' => 'per_session',
                        'allow_manual_input' => true,
                        'location_required' => false, // Easier for testing
                    ],
                ],
            ]
        );

        $this->command->info("School: {$school->name} ready.");

        // 2. Create School Admin
        $admin = User::firstOrCreate(
            ['email' => 'admin@sdmongisidi.sch.id'],
            [
                'name' => 'Admin Mongisidi',
                'username' => 'admin_mongisidi',
                'password' => Hash::make('password'),
                'role_type' => 'school_admin',
                'school_id' => $school->id,
                'is_active' => true,
            ]
        );
        $admin->assignRole('school_admin');

        // 3. Create Academic Year
        $academicYear = AcademicYear::firstOrCreate([
            'school_id' => $school->id,
            'name' => '2025/2026',
            'semester' => '1',
            'is_active' => true,
        ], [
            'start_date' => Carbon::parse('2025-07-01'),
            'end_date' => Carbon::parse('2026-06-30'),
        ]);

        // 4. Create Teachers (6 Homeroom + 2 Subject)
        $teachersData = [
            ['name' => 'Budi Santoso', 'email' => 'budi@sdmongisidi.sch.id', 'username' => 'guru_budi', 'role' => 'teacher'],
            ['name' => 'Siti Aminah', 'email' => 'siti@sdmongisidi.sch.id', 'username' => 'guru_siti', 'role' => 'teacher'],
            ['name' => 'Rahmat Hidayat', 'email' => 'rahmat@sdmongisidi.sch.id', 'username' => 'guru_rahmat', 'role' => 'teacher'],
            ['name' => 'Dewi Sartika', 'email' => 'dewi@sdmongisidi.sch.id', 'username' => 'guru_dewi', 'role' => 'teacher'],
            ['name' => 'Eko Prasetyo', 'email' => 'eko@sdmongisidi.sch.id', 'username' => 'guru_eko', 'role' => 'teacher'],
            ['name' => 'Fajar Nugraha', 'email' => 'fajar@sdmongisidi.sch.id', 'username' => 'guru_fajar', 'role' => 'teacher'],
            ['name' => 'Guru PJOK', 'email' => 'pjok@sdmongisidi.sch.id', 'username' => 'guru_pjok', 'role' => 'teacher'],
            ['name' => 'Guru Agama', 'email' => 'agama@sdmongisidi.sch.id', 'username' => 'guru_agama', 'role' => 'teacher'],
        ];

        $teachers = [];
        foreach ($teachersData as $tData) {
            $teacher = User::firstOrCreate(
                ['email' => $tData['email']],
                [
                    'name' => $tData['name'],
                    'username' => $tData['username'],
                    'password' => Hash::make('password'),
                    'role_type' => $tData['role'],
                    'school_id' => $school->id,
                    'is_active' => true,
                ]
            );
            $teacher->assignRole('teacher');
            $teachers[] = $teacher;

            // Create Profile
            UserProfile::firstOrCreate(['user_id' => $teacher->id], [
                'full_name' => $tData['name'],
                'nip' => str_pad(rand(1, 99999999), 18, '0', STR_PAD_LEFT),
                'gender' => rand(0, 1) ? 'male' : 'female',
            ]);
        }

        // 4b. Create Extra Teachers to reach 20 total
        for ($i = 1; $i <= 12; $i++) {
            $username = "guru_extra_$i";
            $teacher = User::firstOrCreate(
                ['username' => $username],
                [
                    'name' => "Guru Tambahan $i",
                    'email' => "guru.extra.$i@sdmongisidi.sch.id",
                    'password' => Hash::make('password'),
                    'role_type' => 'teacher',
                    'school_id' => $school->id,
                    'is_active' => true,
                ]
            );
            $teacher->assignRole('teacher');
            $teachers[] = $teacher;

            UserProfile::updateOrCreate(['user_id' => $teacher->id], [
                'full_name' => "Guru Tambahan $i",
                'nip' => str_pad(rand(1, 99999999), 18, '0', STR_PAD_LEFT),
                'gender' => rand(0, 1) ? 'male' : 'female',
            ]);
        }

        // 5. Create Classes (Grades 1-6)
        $classesConfig = [
            ['name' => '1A', 'grade' => 1, 'teacher_idx' => 0],
            ['name' => '2A', 'grade' => 2, 'teacher_idx' => 1],
            ['name' => '3A', 'grade' => 3, 'teacher_idx' => 2],
            ['name' => '4A', 'grade' => 4, 'teacher_idx' => 3],
            ['name' => '5A', 'grade' => 5, 'teacher_idx' => 4],
            ['name' => '6A', 'grade' => 6, 'teacher_idx' => 5],
        ];

        $classes = [];
        foreach ($classesConfig as $cConfig) {
            $homeroomTeacher = $teachers[$cConfig['teacher_idx']];

            // Insert Class
            $class = \App\Models\ClassModel::firstOrCreate(
                [
                    'school_id' => $school->id,
                    'academic_year_id' => $academicYear->id,
                    'name' => $cConfig['name'],
                ],
                [
                    'grade_level' => $cConfig['grade'],
                    'homeroom_teacher_id' => $homeroomTeacher->id,
                    'is_active' => true,
                ]
            );
            $classId = $class->id;

            // Assign Teacher Role as Homeroom
            TeacherRole::updateOrCreate(
                [
                    'teacher_id' => $homeroomTeacher->id,
                    'academic_year_id' => $academicYear->id,
                ],
                [
                    'is_homeroom_teacher' => true,
                    'homeroom_class_id' => $classId,
                ]
            );

            // Also assign role
            if (! $homeroomTeacher->hasRole('homeroom_teacher')) {
                $homeroomTeacher->assignRole('homeroom_teacher');
            }

            $classes[] = ['id' => $classId, 'name' => $cConfig['name']];
        }

        // 6. Create Subjects
        $subjectsData = [
            ['name' => 'Tematik', 'code' => 'TEM'],
            ['name' => 'Matematika', 'code' => 'MAT'],
            ['name' => 'PJOK', 'code' => 'PJK'],
            ['name' => 'Agama Islam', 'code' => 'PAI'],
        ];

        $subjects = [];
        foreach ($subjectsData as $sData) {
            $subjects[] = Subject::firstOrCreate([
                'school_id' => $school->id,
                'code' => $sData['code'],
            ], [
                'name' => $sData['name'],
                'school_level' => 'SD',
                'grade_level' => 1, // Generic for SD for now
                'is_active' => true,
            ]);
        }

        // 7. Create Students
        $students = [];
        foreach ($classes as $idx => $cls) {
            // Create 5 students per class
            for ($i = 1; $i <= 5; $i++) {
                $username = strtolower("siswa_{$cls['name']}_{$i}");
                $student = User::firstOrCreate(
                    ['username' => $username],
                    [
                        'name' => "Siswa {$cls['name']} Absen {$i}",
                        'email' => "{$username}@sdmongisidi.sch.id",
                        'password' => Hash::make('password'),
                        'role_type' => 'student',
                        'school_id' => $school->id,
                        'is_active' => true,
                    ]
                );
                $student->assignRole('student');

                // 7b. Create Parent
                $parentName = str_replace('Siswa', 'Orang Tua', $student->name);
                $parentUsername = 'ortu_'.$username;
                $parent = User::firstOrCreate(
                    ['username' => $parentUsername],
                    [
                        'name' => $parentName,
                        'email' => "{$parentUsername}@sdmongisidi.sch.id",
                        'password' => Hash::make('password'),
                        'role_type' => 'parent',
                        'school_id' => $school->id,
                        'is_active' => true,
                    ]
                );
                $parent->assignRole('parent'); // Ensure role exists

                // Link Parent <-> Student
                DB::table('student_parents')->insertOrIgnore([
                    'student_id' => $student->id,
                    'parent_id' => $parent->id,
                    'relationship' => 'Ayah', // Default
                    'is_primary' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $students[] = $student;
            }
        }

        // 8. Create Schedules for Whole Week (Mon-Fri) using integer 0–6 (Carbon::dayOfWeek)
        $days = [1, 2, 3, 4, 5];

        foreach ($days as $day) {
            foreach ($classes as $class) {
                // Determine Homeroom Teacher
                $classData = array_filter($classesConfig, fn ($c) => $c['name'] === $class['name']);
                $classData = reset($classData);
                $homeroomTeacher = $teachers[$classData['teacher_idx']];

                // Schedule 1: 07:30 - 09:00 (Homeroom Teacher - Tematik / Math)
                // Mon(1)/Wed(3)/Fri(5) = Tematik, Tue(2)/Thu(4) = Math
                $subject1 = in_array($day, [1, 3, 5]) ? $subjects[0] : $subjects[1];

                DB::table('schedules')->insertOrIgnore([
                    'school_id' => $school->id,
                    'academic_year_id' => $academicYear->id,
                    'class_id' => $class['id'],
                    'subject_id' => $subject1->id,
                    'teacher_id' => $homeroomTeacher->id,
                    'day_of_week' => $day,
                    'start_time' => '07:30:00',
                    'end_time' => '09:00:00',
                    'room' => "Ruang {$class['name']}",
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Schedule 2: 09:30 - 11:00 (PJOK / Agama / Tematik)
                // Mon(1) = PJOK, Tue(2) = Agama, Others = Tematik
                $subject2 = match ($day) {
                    1 => $subjects[2],
                    2 => $subjects[3],
                    default => $subjects[0],
                };

                $teacher2 = match ($day) {
                    1 => $teachers[6],
                    2 => $teachers[7],
                    default => $homeroomTeacher,
                };

                DB::table('schedules')->insertOrIgnore([
                    'school_id' => $school->id,
                    'academic_year_id' => $academicYear->id,
                    'class_id' => $class['id'],
                    'subject_id' => $subject2->id,
                    'teacher_id' => $teacher2->id,
                    'day_of_week' => $day,
                    'start_time' => '09:30:00',
                    'end_time' => '11:00:00',
                    'room' => match ($day) {
                        1 => 'Lapangan',
                        2 => 'Musholla',
                        default => "Ruang {$class['name']}"
                    },
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->command->info('Seeding Mongisidi Finished!');
        $this->command->info('- Admin: admin_mongisidi / password');
        $this->command->info('- Teachers: guru_budi, guru_siti, etc / password');
        $this->command->info('- Students: siswa_1a_1, etc / password');
    }
}
