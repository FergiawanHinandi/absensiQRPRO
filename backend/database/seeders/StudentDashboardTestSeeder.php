<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StudentDashboardTestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create test school if not exists
        $school = DB::table('schools')->where('code', 'TEST_SCHOOL')->first();
        if (! $school) {
            $schoolId = DB::table('schools')->insertGetId([
                'name' => 'SMA Test Jakarta',
                'code' => 'TEST_SCHOOL',
                'address' => 'Jakarta Selatan',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $schoolId = $school->id;
        }

        // Create academic year
        $academicYear = DB::table('academic_years')->where('name', '2024/2025')->first();
        if (! $academicYear) {
            $academicYearId = DB::table('academic_years')->insertGetId([
                'name' => '2024/2025',
                'start_date' => '2024-07-15',
                'end_date' => '2025-06-30',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $academicYearId = $academicYear->id;
        }

        // Create grade
        $grade = DB::table('grades')->where('name', 'Kelas X')->first();
        if (! $grade) {
            $gradeId = DB::table('grades')->insertGetId([
                'name' => 'Kelas X',
                'level' => 10,
                'academic_year_id' => $academicYearId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $gradeId = $grade->id;
        }

        // Create subjects
        $subjects = [
            ['code' => 'MTK', 'name' => 'Matematika', 'grade_level' => 10, 'school_level' => 'SMA'],
            ['code' => 'BIN', 'name' => 'Bahasa Indonesia', 'grade_level' => 10, 'school_level' => 'SMA'],
            ['code' => 'BIG', 'name' => 'Bahasa Inggris', 'grade_level' => 10, 'school_level' => 'SMA'],
            ['code' => 'FIS', 'name' => 'Fisika', 'grade_level' => 10, 'school_level' => 'SMA'],
        ];

        foreach ($subjects as $subject) {
            DB::table('subjects')->updateOrInsert(
                ['code' => $subject['code']],
                array_merge($subject, [
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        // Create test teacher
        $teacher = DB::table('users')->where('email', 'teacher@test.com')->first();
        if (! $teacher) {
            $teacherId = DB::table('users')->insertGetId([
                'name' => 'Guru Test',
                'username' => 'teacher_test',
                'email' => 'teacher@test.com',
                'password' => Hash::make('password'),
                'role' => 'teacher',
                'school_id' => $schoolId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $teacherId = $teacher->id;
        }

        // Create class
        $class = DB::table('classes')->where('name', 'X-IPA-1')->first();
        if (! $class) {
            $classId = DB::table('classes')->insertGetId([
                'name' => 'X-IPA-1',
                'grade_id' => $gradeId,
                'school_id' => $schoolId,
                'homeroom_teacher_id' => $teacherId,
                'max_students' => 36,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $classId = $class->id;
        }

        // Create test student
        $student = DB::table('users')->where('email', 'student@test.com')->first();
        if (! $student) {
            $studentId = DB::table('users')->insertGetId([
                'name' => 'Siswa Test',
                'username' => 'student_test',
                'email' => 'student@test.com',
                'phone' => '+628123456789',
                'password' => Hash::make('password'),
                'role' => 'student',
                'school_id' => $schoolId,
                'class_id' => $classId,
                'photo_url' => 'https://via.placeholder.com/150',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $studentId = $student->id;
            // Update class_id if needed
            DB::table('users')->where('id', $studentId)->update(['class_id' => $classId]);
        }

        // Create schedules for today
        $today = Carbon::today();
        $dayOfWeek = $today->dayOfWeek;

        $schedules = [
            ['subject_code' => 'MTK', 'start_time' => '07:30', 'end_time' => '09:00', 'room' => 'Ruang 1'],
            ['subject_code' => 'BIN', 'start_time' => '09:15', 'end_time' => '10:45', 'room' => 'Ruang 2'],
            ['subject_code' => 'BIG', 'start_time' => '11:00', 'end_time' => '12:30', 'room' => 'Ruang 3'],
            ['subject_code' => 'FIS', 'start_time' => '13:30', 'end_time' => '15:00', 'room' => 'Lab Fisika'],
        ];

        foreach ($schedules as $scheduleData) {
            $subject = DB::table('subjects')->where('code', $scheduleData['subject_code'])->first();
            if ($subject) {
                $existingSchedule = DB::table('schedules')
                    ->where('class_id', $classId)
                    ->where('subject_id', $subject->id)
                    ->where('day_of_week', $dayOfWeek)
                    ->first();

                if (! $existingSchedule) {
                    $scheduleId = DB::table('schedules')->insertGetId([
                        'class_id' => $classId,
                        'subject_id' => $subject->id,
                        'teacher_id' => $teacherId,
                        'academic_year_id' => $academicYearId,
                        'day_of_week' => $dayOfWeek,
                        'start_time' => $scheduleData['start_time'],
                        'end_time' => $scheduleData['end_time'],
                        'room' => $scheduleData['room'],
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Create some sample attendance logs for the past month
                    for ($i = 1; $i <= 20; $i++) {
                        $date = Carbon::now()->subDays($i);
                        if ($date->dayOfWeek === $dayOfWeek) {
                            $status = ['present', 'present', 'present', 'late', 'absent'][array_rand(['present', 'present', 'present', 'late', 'absent'])];

                            DB::table('attendance_logs')->insert([
                                'student_id' => $studentId,
                                'schedule_id' => $scheduleId,
                                'status' => $status,
                                'scanned_at' => $date->setTime(rand(7, 8), rand(30, 59)),
                                'created_at' => $date,
                                'updated_at' => $date,
                            ]);
                        }
                    }
                }
            }
        }

        // Create today's attendance (if it's a school day)
        if ($dayOfWeek >= 1 && $dayOfWeek <= 5) {
            $firstSchedule = DB::table('schedules')
                ->where('class_id', $classId)
                ->where('day_of_week', $dayOfWeek)
                ->orderBy('start_time')
                ->first();

            if ($firstSchedule) {
                $existingAttendance = DB::table('attendance_logs')
                    ->where('student_id', $studentId)
                    ->whereDate('created_at', $today)
                    ->first();

                if (! $existingAttendance) {
                    DB::table('attendance_logs')->insert([
                        'student_id' => $studentId,
                        'schedule_id' => $firstSchedule->id,
                        'status' => 'present',
                        'scanned_at' => $today->setTime(7, 35),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        $this->command->info('Student Dashboard test data created successfully!');
        $this->command->info('Test Student: student@test.com / password');
        $this->command->info('Test Teacher: teacher@test.com / password');
    }
}
