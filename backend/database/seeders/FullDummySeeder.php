<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\ClassModel;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserProfile;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class FullDummySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create Disposable School
        $school = School::firstOrCreate(
            ['name' => 'Sekolah Dummy (Siap Hapus)'],
            [
                'npsn' => '99999999',
                'address' => 'Jl. Data Dummy No. 0',
                'phone' => '000-000000',
                'email' => 'dummy@delete.me',
                'school_level' => 'SMA',
                'is_active' => true,
                'settings' => [
                    'attendance' => [
                        'qr_expiry_minutes' => 5,
                        'late_tolerance_minutes' => 10,
                        'qr_mode' => 'per_session',
                        'allow_manual_input' => true,
                        'location_required' => false,
                    ],
                ],
            ]
        );

        $this->command->info("Created School: {$school->name}");

        // 2. Create School Admin
        $admin = User::firstOrCreate(
            ['email' => 'admin@dummy.sch.id'],
            [
                'name' => 'Admin Dummy',
                'username' => 'admin_dummy',
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
            'start_date' => Carbon::now()->startOfYear(),
            'end_date' => Carbon::now()->endOfYear(),
        ]);

        // 4. Create Teachers
        $teachers = [];
        for ($i = 1; $i <= 20; $i++) {
            $t = User::create([
                'name' => "Guru Dummy $i",
                'email' => "guru.dummy$i@dummy.sch.id",
                'username' => "guru_dummy_$i",
                'password' => Hash::make('password'),
                'role_type' => 'teacher',
                'school_id' => $school->id,
                'is_active' => true,
            ]);
            $t->assignRole('teacher');
            $teachers[] = $t;

            UserProfile::create([
                'user_id' => $t->id,
                'full_name' => $t->name,
                'nip' => 'DUMMY'.rand(100000, 999999),
                'gender' => rand(0, 1) ? 'male' : 'female',
            ]);
        }

        // 5. Create Classes (SMA 10, 11, 12 - IPA/IPS)
        $classNames = ['10-IPA-1', '10-IPS-1', '11-IPA-1', '11-IPS-1', '12-IPA-1'];
        $classes = [];
        foreach ($classNames as $idx => $name) {
            $homeroom = $teachers[$idx]; // Assign first 5 teachers as homeroom

            $cls = ClassModel::create([
                'school_id' => $school->id,
                'academic_year_id' => $academicYear->id,
                'name' => $name,
                'grade_level' => (int) explode('-', $name)[0],
                'homeroom_teacher_id' => $homeroom->id,
                'is_active' => true,
            ]);

            $homeroom->assignRole('homeroom_teacher');
            DB::table('teacher_roles')->insertOrIgnore([
                'teacher_id' => $homeroom->id,
                'academic_year_id' => $academicYear->id,
                'is_homeroom_teacher' => true,
                'homeroom_class_id' => $cls->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $classes[] = $cls;
        }

        // 6. Create Subjects
        $subjectsConfig = [
            ['name' => 'Matematika', 'code' => 'MAT'],
            ['name' => 'Fisika', 'code' => 'FIS'],
            ['name' => 'Biologi', 'code' => 'BIO'],
            ['name' => 'Kimia', 'code' => 'KIM'],
            ['name' => 'Bahasa Indonesia', 'code' => 'IND'],
            ['name' => 'Bahasa Inggris', 'code' => 'ING'],
        ];
        $subjects = [];
        foreach ($subjectsConfig as $sConf) {
            $subjects[] = Subject::firstOrCreate([
                'school_id' => $school->id,
                'code' => $sConf['code'],
                'grade_level' => 10, // Generic
            ], [
                'name' => $sConf['name'],
                'school_level' => 'SMA',
                'is_active' => true,
            ]);
        }

        // 7. Create Students & Parents
        $students = [];
        foreach ($classes as $idx => $cls) {
            // 4 Students per class = 20 Total
            for ($i = 1; $i <= 4; $i++) {
                $sName = "Siswa {$cls->name} $i";
                $username = strtolower(str_replace([' ', '-'], '', $sName));

                // Student
                $student = User::create([
                    'name' => $sName,
                    'email' => "$username@dummy.sch.id",
                    'username' => $username,
                    'password' => Hash::make('password'),
                    'role_type' => 'student',
                    'school_id' => $school->id,
                    'is_active' => true,
                ]);
                $student->assignRole('student');

                // Class Pivot
                DB::table('class_students')->insert([
                    'class_id' => $cls->id,
                    'student_id' => $student->id,
                    'status' => 'active',
                    'enrollment_date' => now()->subMonths(1),
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                // Parent
                $pName = "Ortu {$sName}";
                $pUsername = "ortu_$username";
                $parent = User::create([
                    'name' => $pName,
                    'email' => "$pUsername@dummy.sch.id",
                    'username' => $pUsername,
                    'password' => Hash::make('password'),
                    'role_type' => 'parent',
                    'school_id' => $school->id,
                    'is_active' => true,
                ]);
                $parent->assignRole('parent');

                DB::table('student_parents')->insert([
                    'student_id' => $student->id,
                    'parent_id' => $parent->id,
                    'relationship' => 'Wali',
                    'is_primary' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                $students[] = $student;
            }
        }

        // 8. Create Schedules (Mon-Fri)
        $this->command->info('Creating Schedules...');
        $days = [1, 2, 3, 4, 5];
        $scheduleIds = [];

        foreach ($days as $dayIdx => $day) {
            foreach ($classes as $cIdx => $cls) {
                // 3 Subjects per day
                for ($session = 0; $session < 3; $session++) {
                    $subj = $subjects[($dayIdx + $session) % count($subjects)];
                    $teacher = $teachers[($cIdx + $session) % count($teachers)];

                    $startHour = 8 + ($session * 2); // 08:00, 10:00, 12:00

                    $schId = DB::table('schedules')->insertGetId([
                        'school_id' => $school->id,
                        'academic_year_id' => $academicYear->id,
                        'class_id' => $cls->id,
                        'subject_id' => $subj->id,
                        'teacher_id' => $teacher->id,
                        'day_of_week' => $day,
                        'start_time' => sprintf('%02d:00:00', $startHour),
                        'end_time' => sprintf('%02d:00:00', $startHour + 2),
                        'room' => "R-{$cls->name}",
                        'is_active' => true,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);

                    $scheduleIds[] = ['id' => $schId, 'day' => $day, 'class_id' => $cls->id];
                }
            }
        }

        // 9. Generate Attendance History (Last 30 days)
        $this->command->info('Generating Attendance History (Heavy)...');
        $startDate = Carbon::now()->subDays(30);
        $endDate = Carbon::now();

        for ($date = $startDate; $date->lte($endDate); $date->addDay()) {
            if ($date->isWeekend()) {
                continue;
            }

            $dayInt = $date->dayOfWeek;

            // Find schedules for this day
            $dailySchedules = array_filter($scheduleIds, fn ($s) => $s['day'] === $dayInt);

            foreach ($dailySchedules as $sch) {
                // Get students for this class
                $classStudents = DB::table('class_students')
                    ->where('class_id', $sch['class_id'])
                    ->pluck('student_id');

                foreach ($classStudents as $stuId) {
                    // Random status: 80% Present, 10% Late, 5% Sick, 5% Alpha
                    $rand = rand(1, 100);
                    $status = 'present';
                    $checkIn = $date->copy()->setTime(rand(7, 8), rand(0, 59));

                    if ($rand > 80) {
                        $status = 'late';
                        $checkIn = $date->copy()->setTime(9, rand(0, 30));
                    } elseif ($rand > 90) {
                        $status = 'sick';
                        $checkIn = null;
                    } elseif ($rand > 95) {
                        $status = 'alpha';
                        $checkIn = null;
                    }

                    // Skip recording randomly for realism
                    // if (rand(1, 100) > 98) continue;

                    DB::table('attendances')->insert([
                        'school_id' => $school->id,
                        'schedule_id' => $sch['id'],
                        'student_id' => $stuId,
                        'attendance_date' => $date->toDateString(),
                        'status' => $status,
                        'check_in_time' => $checkIn,
                        'is_manual' => in_array($status, ['sick', 'permission', 'alpha']),
                        'created_at' => $date, 'updated_at' => $date,
                    ]);
                }
            }
        }

        $this->command->info("Full Dummy Data Seeded! You can delete 'Sekolah Dummy (Siap Hapus)' via Super Admin Dashboard.");
    }
}
