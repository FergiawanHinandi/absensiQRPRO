<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\ClassModel;
use App\Models\ClassStudent;
use App\Models\School; // Gunakan ClassModel
use App\Models\Subject;
use App\Models\User; // Gunakan UserProfile
use App\Models\UserProfile; // Gunakan ClassStudent pivot
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $school = School::where('name', 'SMP Negeri 1 Jakarta')->first() ?? School::first();
        if (! $school) {
            $this->command->error('No school found. Please run SchoolSeeder first.');

            return;
        }

        $school->update([
            'settings' => [
                'attendance' => [
                    'qr_expiry_minutes' => 10,
                    'late_tolerance_minutes' => 15,
                    'qr_mode' => 'per_session',
                    'allow_manual_input' => true,
                    'location_required' => true,
                ],
                'notifications' => [
                    'push_enabled' => true,
                    'email_enabled' => false,
                    'wa_enabled' => false,
                ],
                'holidays' => [
                    ['name' => 'Libur Nasional', 'date' => now()->addDays(7)->toDateString()],
                    ['name' => 'Libur Semester', 'date' => now()->addDays(30)->toDateString()],
                ],
                'academic_calendar' => [
                    ['title' => 'UTS Semester 1', 'date' => now()->addWeeks(4)->toDateString()],
                    ['title' => 'UAS Semester 1', 'date' => now()->addWeeks(10)->toDateString()],
                ],
            ],
        ]);

        $academicYear = AcademicYear::firstOrCreate([
            'school_id' => $school->id,
            'name' => '2025/2026',
            'semester' => '1',
            'is_active' => true,
        ], [
            'start_date' => now()->startOfYear(),
            'end_date' => now()->endOfYear(),
        ]);

        $createUser = function (array $payload, array $profile = []) use ($school) {
            $user = User::firstOrCreate([
                'email' => $payload['email'] ?? null,
                'username' => $payload['username'],
            ], array_merge([
                'name' => $payload['name'],
                'password' => Hash::make($payload['password'] ?? 'password'),
                'role_type' => $payload['role_type'],
                'school_id' => $school->id,
                'is_active' => true,
            ], $payload['extra'] ?? []));

            if (! empty($payload['role_type'])) {
                $user->assignRole($payload['role_type']);
            }

            if (! empty($profile)) {
                UserProfile::firstOrCreate(['user_id' => $user->id], $profile);
            }

            return $user;
        };

        $admin = $createUser([
            'name' => 'Admin Sekolah SMP 1',
            'email' => 'admin@smp1.com',
            'username' => 'admin1',
            'role_type' => 'school_admin',
        ], [
            'full_name' => 'Admin SMP 1',
            'gender' => 'male',
            'phone' => '081111111111',
        ]);

        $teachers = [
            $createUser([
                'name' => 'Budi Santoso',
                'email' => 'guru.mtk@smp1.com',
                'username' => 'guru_mtk',
                'role_type' => 'teacher',
            ], [
                'full_name' => 'Budi Santoso, S.Pd',
                'nip' => '198001012000011001',
                'gender' => 'male',
                'phone' => '081234567890',
            ]),
            $createUser([
                'name' => 'Siti Rahma',
                'email' => 'guru.ipa@smp1.com',
                'username' => 'guru_ipa',
                'role_type' => 'teacher',
            ], [
                'full_name' => 'Siti Rahma, S.Pd',
                'nip' => '198602022006022002',
                'gender' => 'female',
                'phone' => '081234567891',
            ]),
            $createUser([
                'name' => 'Andi Pratama',
                'email' => 'guru.ips@smp1.com',
                'username' => 'guru_ips',
                'role_type' => 'teacher',
            ], [
                'full_name' => 'Andi Pratama, S.Pd',
                'nip' => '198701052007031003',
                'gender' => 'male',
                'phone' => '081234567892',
            ]),
            $createUser([
                'name' => 'Rina Lestari',
                'email' => 'guru.ing@smp1.com',
                'username' => 'guru_ing',
                'role_type' => 'teacher',
            ], [
                'full_name' => 'Rina Lestari, S.Pd',
                'nip' => '198902102009042004',
                'gender' => 'female',
                'phone' => '081234567893',
            ]),
        ];

        $parents = [
            $createUser([
                'name' => 'Orang Tua 1',
                'email' => 'ortu1@smp1.com',
                'username' => 'ortu1',
                'role_type' => 'parent',
            ], [
                'full_name' => 'Orang Tua 1',
                'gender' => 'female',
            ]),
            $createUser([
                'name' => 'Orang Tua 2',
                'email' => 'ortu2@smp1.com',
                'username' => 'ortu2',
                'role_type' => 'parent',
            ], [
                'full_name' => 'Orang Tua 2',
                'gender' => 'male',
            ]),
        ];

        $classConfigs = [
            ['name' => '7A', 'grade_level' => 7, 'homeroom_teacher_id' => $teachers[0]->id],
            ['name' => '7B', 'grade_level' => 7, 'homeroom_teacher_id' => $teachers[1]->id],
            ['name' => '8A', 'grade_level' => 8, 'homeroom_teacher_id' => $teachers[2]->id],
            ['name' => '9A', 'grade_level' => 9, 'homeroom_teacher_id' => $teachers[3]->id],
        ];

        $classes = [];
        foreach ($classConfigs as $config) {
            $classId = DB::table('classes')
                ->where('school_id', $school->id)
                ->where('name', $config['name'])
                ->value('id');

            if (! $classId) {
                $classId = DB::table('classes')->insertGetId([
                    'school_id' => $school->id,
                    'academic_year_id' => $academicYear->id,
                    'name' => $config['name'],
                    'grade_level' => $config['grade_level'],
                    'homeroom_teacher_id' => $config['homeroom_teacher_id'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('classes')->where('id', $classId)->update([
                    'homeroom_teacher_id' => $config['homeroom_teacher_id'],
                    'updated_at' => now(),
                ]);
            }
            $classes[] = ['id' => $classId, 'name' => $config['name'], 'grade_level' => $config['grade_level']];
        }

        $subjects = [
            ['name' => 'Matematika', 'code' => 'MAT-7', 'grade_level' => 7],
            ['name' => 'IPA', 'code' => 'IPA-7', 'grade_level' => 7],
            ['name' => 'IPS', 'code' => 'IPS-8', 'grade_level' => 8],
            ['name' => 'Bahasa Inggris', 'code' => 'ENG-8', 'grade_level' => 8],
            ['name' => 'PPKn', 'code' => 'PPK-9', 'grade_level' => 9],
        ];

        $subjectRecords = [];
        foreach ($subjects as $subject) {
            $subjectRecords[] = Subject::firstOrCreate([
                'school_id' => $school->id,
                'name' => $subject['name'],
                'code' => $subject['code'],
            ], [
                'school_level' => 'SMP',
                'grade_level' => $subject['grade_level'],
                'is_active' => true,
            ]);
        }

        $students = [];
        for ($i = 1; $i <= 20; $i++) {
            $username = 'siswa'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $students[] = $createUser([
                'name' => 'Siswa '.$i,
                'email' => $username.'@smp1.com',
                'username' => $username,
                'role_type' => 'student',
            ], [
                'full_name' => 'Siswa '.$i,
                'nisn' => str_pad((string) (1000000000 + $i), 10, '0', STR_PAD_LEFT),
                'gender' => $i % 2 === 0 ? 'female' : 'male',
                'birth_date' => '2010-01-01',
            ]);
        }

        foreach ($students as $index => $student) {
            $class = $classes[$index % count($classes)];
            $existing = DB::table('class_students')
                ->where('student_id', $student->id)
                ->where('status', 'active')
                ->first();

            if ($existing && (int) $existing->class_id === (int) $class['id']) {
                continue;
            }

            if ($existing) {
                DB::table('class_students')->where('id', $existing->id)->update([
                    'status' => 'moved',
                    'updated_at' => now(),
                ]);
            }

            DB::table('class_students')->insert([
                'class_id' => $class['id'],
                'student_id' => $student->id,
                'enrollment_date' => now()->toDateString(),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $scheduleDays = [1, 2, 3, 4, 5];
        $scheduleTimes = [
            ['07:00:00', '08:30:00'],
            ['08:40:00', '10:10:00'],
        ];

        foreach ($classes as $class) {
            foreach ($scheduleDays as $day) {
                foreach ($scheduleTimes as $index => $time) {
                    $subject = $subjectRecords[$index % count($subjectRecords)];
                    $teacher = $teachers[$index % count($teachers)];

                    $exists = DB::table('schedules')
                        ->where('school_id', $school->id)
                        ->where('class_id', $class['id'])
                        ->where('subject_id', $subject->id)
                        ->where('day_of_week', $day)
                        ->where('start_time', $time[0])
                        ->exists();

                    if (! $exists) {
                        DB::table('schedules')->insert([
                            'school_id' => $school->id,
                            'academic_year_id' => $academicYear->id,
                            'class_id' => $class['id'],
                            'subject_id' => $subject->id,
                            'teacher_id' => $teacher->id,
                            'day_of_week' => $day,
                            'start_time' => $time[0],
                            'end_time' => $time[1],
                            'room' => 'R-'.$class['name'],
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $assignmentExists = DB::table('teacher_subjects')
                        ->where('teacher_id', $teacher->id)
                        ->where('subject_id', $subject->id)
                        ->where('class_id', $class['id'])
                        ->where('academic_year_id', $academicYear->id)
                        ->exists();

                    if (! $assignmentExists) {
                        DB::table('teacher_subjects')->insert([
                            'teacher_id' => $teacher->id,
                            'subject_id' => $subject->id,
                            'class_id' => $class['id'],
                            'academic_year_id' => $academicYear->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        }

        $today = now()->dayOfWeek;
        $todaySchedules = DB::table('schedules')
            ->where('school_id', $school->id)
            ->where('day_of_week', $today)
            ->get();

        foreach ($todaySchedules as $index => $schedule) {
            if ($index % 2 === 0) {
                $token = Str::random(40);
                $exists = DB::table('qr_codes')
                    ->where('schedule_id', $schedule->id)
                    ->whereDate('created_at', now()->toDateString())
                    ->exists();
                if (! $exists) {
                    DB::table('qr_codes')->insert([
                        'school_id' => $school->id,
                        'schedule_id' => $schedule->id,
                        'token' => $token,
                        'qr_type' => 'in',
                        'generated_by' => $schedule->teacher_id,
                        'valid_from' => now()->subMinutes(5),
                        'valid_until' => now()->addMinutes(15),
                        'max_scans' => null,
                        'scan_count' => 0,
                        'is_active' => true,
                        'location_required' => true,
                        'metadata' => json_encode(['seed' => true]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        foreach ($classes as $class) {
            $schedule = DB::table('schedules')
                ->where('class_id', $class['id'])
                ->where('day_of_week', $today)
                ->first();
            if (! $schedule) {
                continue;
            }

            $studentIds = DB::table('class_students')
                ->where('class_id', $class['id'])
                ->where('status', 'active')
                ->limit(10)
                ->pluck('student_id');

            foreach ($studentIds as $idx => $studentId) {
                $status = match (true) {
                    $idx === 0 => 'late',
                    $idx === 1 => 'sick',
                    $idx === 2 => 'permit',
                    $idx === 3 => 'absent',
                    default => 'present',
                };

                $exists = DB::table('attendances')
                    ->where('schedule_id', $schedule->id)
                    ->where('student_id', $studentId)
                    ->where('attendance_date', now()->toDateString())
                    ->exists();

                if (! $exists) {
                    DB::table('attendances')->insert([
                        'school_id' => $school->id,
                        'schedule_id' => $schedule->id,
                        'student_id' => $studentId,
                        'attendance_date' => now()->toDateString(),
                        'status' => $status,
                        'check_in_time' => $status === 'present' || $status === 'late' ? now()->subMinutes(rand(1, 10)) : null,
                        'is_manual' => $status !== 'present',
                        'notes' => $status === 'late' ? 'Terlambat' : null,
                        'recorded_by' => $admin->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        $reportExists = DB::table('attendance_reports')
            ->where('school_id', $school->id)
            ->where('report_type', 'daily')
            ->where('report_date', now()->toDateString())
            ->exists();

        if (! $reportExists) {
            DB::table('attendance_reports')->insert([
                'school_id' => $school->id,
                'report_type' => 'daily',
                'class_id' => $classes[0]['id'],
                'report_date' => now()->toDateString(),
                'period_start' => now()->toDateString(),
                'period_end' => now()->toDateString(),
                'total_days' => 1,
                'present_count' => 20,
                'late_count' => 2,
                'absent_count' => 1,
                'sick_count' => 1,
                'permit_count' => 1,
                'attendance_rate' => 92.5,
                'report_data' => json_encode(['seed' => true]),
                'file_url' => null,
                'generated_by' => $admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $monthlyExists = DB::table('attendance_reports')
            ->where('school_id', $school->id)
            ->where('report_type', 'monthly')
            ->where('report_date', now()->startOfMonth()->toDateString())
            ->exists();

        if (! $monthlyExists) {
            DB::table('attendance_reports')->insert([
                'school_id' => $school->id,
                'report_type' => 'monthly',
                'class_id' => null,
                'report_date' => now()->startOfMonth()->toDateString(),
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'total_days' => now()->daysInMonth,
                'present_count' => 320,
                'late_count' => 12,
                'absent_count' => 5,
                'sick_count' => 7,
                'permit_count' => 4,
                'attendance_rate' => 95.2,
                'report_data' => json_encode(['seed' => true]),
                'file_url' => null,
                'generated_by' => $admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $semesterExists = DB::table('attendance_reports')
            ->where('school_id', $school->id)
            ->where('report_type', 'semester')
            ->where('report_date', now()->startOfMonth()->toDateString())
            ->exists();

        if (! $semesterExists) {
            DB::table('attendance_reports')->insert([
                'school_id' => $school->id,
                'report_type' => 'semester',
                'class_id' => null,
                'report_date' => now()->startOfMonth()->toDateString(),
                'period_start' => now()->subMonths(5)->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'total_days' => 120,
                'present_count' => 1500,
                'late_count' => 40,
                'absent_count' => 20,
                'sick_count' => 18,
                'permit_count' => 15,
                'attendance_rate' => 96.1,
                'report_data' => json_encode(['seed' => true]),
                'file_url' => null,
                'generated_by' => $admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->command->info('Demo data seeded successfully for SMP 1.');
    }
}
