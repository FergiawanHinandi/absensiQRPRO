<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class AdminManagementService
{
    public function getTeachers(User $user): array
    {
        $schoolId = $user->school_id;

        $scheduleCounts = DB::table('schedules')
            ->where('school_id', $schoolId)
            ->groupBy('teacher_id')
            ->select('teacher_id', DB::raw('count(*) as total_schedules'))
            ->get()
            ->keyBy('teacher_id');

        $teachers = DB::table('users')
            ->where('school_id', $schoolId)
            ->whereIn('role_type', ['teacher', 'homeroom_teacher'])
            ->select('id', 'name', 'username', 'email', 'role_type', 'is_active', 'last_login_at')
            ->orderBy('name')
            ->get()
            ->map(function ($teacher) use ($scheduleCounts) {
                $count = $scheduleCounts[$teacher->id]->total_schedules ?? 0;

                return [
                    'id' => $teacher->id,
                    'name' => $teacher->name,
                    'username' => $teacher->username,
                    'email' => $teacher->email,
                    'role_type' => $teacher->role_type,
                    'is_active' => (bool) $teacher->is_active,
                    'last_login_at' => $teacher->last_login_at,
                    'total_schedules' => (int) $count,
                ];
            })
            ->values();

        return [
            'total' => $teachers->count(),
            'teachers' => $teachers,
        ];
    }

    public function createTeacher(User $user, array $data): array
    {
        $teacher = User::create([
            'school_id' => $user->school_id,
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'] ?? null,
            'password' => Hash::make($data['password']),
            'role_type' => $data['role_type'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $this->formatTeacher($user->school_id, $teacher);
    }

    public function updateTeacher(User $user, int $teacherId, array $data): array
    {
        $teacher = User::where('school_id', $user->school_id)
            ->whereIn('role_type', ['teacher', 'homeroom_teacher'])
            ->where('id', $teacherId)
            ->firstOrFail();

        $payload = [
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'] ?? null,
        ];

        if (array_key_exists('is_active', $data)) {
            $payload['is_active'] = (bool) $data['is_active'];
        }

        if (! empty($data['password'])) {
            $payload['password'] = Hash::make($data['password']);
        }

        $teacher->update($payload);

        return $this->formatTeacher($user->school_id, $teacher);
    }

    public function updateTeacherStatus(User $user, int $teacherId, bool $isActive): array
    {
        $teacher = User::where('school_id', $user->school_id)
            ->whereIn('role_type', ['teacher', 'homeroom_teacher'])
            ->where('id', $teacherId)
            ->firstOrFail();

        $teacher->update([
            'is_active' => $isActive,
        ]);

        $totalSchedules = DB::table('schedules')
            ->where('school_id', $user->school_id)
            ->where('teacher_id', $teacher->id)
            ->count();

        return [
            'id' => $teacher->id,
            'name' => $teacher->name,
            'username' => $teacher->username,
            'email' => $teacher->email,
            'role_type' => $teacher->role_type,
            'is_active' => (bool) $teacher->is_active,
            'last_login_at' => $teacher->last_login_at,
            'total_schedules' => (int) $totalSchedules,
        ];
    }

    public function getStudents(User $user): array
    {
        $schoolId = $user->school_id;

        $students = DB::table('users as students')
            ->leftJoin('class_students', function ($join) {
                $join->on('class_students.student_id', '=', 'students.id')
                    ->where('class_students.status', '=', 'active');
            })
            ->leftJoin('classes', 'class_students.class_id', '=', 'classes.id')
            ->where('students.school_id', $schoolId)
            ->where('students.role_type', 'student')
            ->select(
                'students.id',
                'students.name',
                'students.username',
                'students.email',
                'students.is_active',
                DB::raw('max(classes.name) as class_name'),
                DB::raw('max(class_students.class_id) as class_id')
            )
            ->groupBy('students.id', 'students.name', 'students.username', 'students.email', 'students.is_active')
            ->orderBy('students.name')
            ->get()
            ->map(fn ($student) => [
                'id' => $student->id,
                'name' => $student->name,
                'username' => $student->username,
                'email' => $student->email,
                'is_active' => (bool) $student->is_active,
                'class_name' => $student->class_name,
                'class_id' => $student->class_id,
            ])
            ->values();

        return [
            'total' => $students->count(),
            'students' => $students,
        ];
    }

    public function createStudent(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data) {
            $student = User::create([
                'school_id' => $user->school_id,
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
                'password' => Hash::make($data['password']),
                'role_type' => 'student',
                'is_active' => $data['is_active'] ?? true,
            ]);

            if (! empty($data['class_id'])) {
                $this->assignStudentClass($user->school_id, $student->id, (int) $data['class_id']);
            }

            return $this->formatStudent($user->school_id, $student->id);
        });
    }

    public function updateStudent(User $user, int $studentId, array $data): array
    {
        return DB::transaction(function () use ($user, $studentId, $data) {
            $student = User::where('school_id', $user->school_id)
                ->where('role_type', 'student')
                ->where('id', $studentId)
                ->firstOrFail();

            $payload = [
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
            ];

            if (array_key_exists('is_active', $data)) {
                $payload['is_active'] = (bool) $data['is_active'];
            }

            if (! empty($data['password'])) {
                $payload['password'] = Hash::make($data['password']);
            }

            $student->update($payload);

            if (array_key_exists('class_id', $data)) {
                $this->assignStudentClass($user->school_id, $student->id, $data['class_id']);
            }

            return $this->formatStudent($user->school_id, $student->id);
        });
    }

    public function getClasses(User $user): array
    {
        $schoolId = $user->school_id;

        $studentCounts = DB::table('class_students')
            ->join('classes', 'class_students.class_id', '=', 'classes.id')
            ->where('classes.school_id', $schoolId)
            ->where('class_students.status', 'active')
            ->groupBy('class_students.class_id')
            ->select('class_students.class_id', DB::raw('count(*) as total_students'))
            ->get()
            ->keyBy('class_id');

        $classes = DB::table('classes')
            ->leftJoin('users as homeroom', 'classes.homeroom_teacher_id', '=', 'homeroom.id')
            ->where('classes.school_id', $schoolId)
            ->select(
                'classes.id',
                'classes.name',
                'classes.grade_level',
                'classes.is_active',
                'homeroom.name as homeroom_teacher'
            )
            ->orderBy('classes.grade_level')
            ->orderBy('classes.name')
            ->get()
            ->map(function ($class) use ($studentCounts) {
                $total = $studentCounts[$class->id]->total_students ?? 0;

                return [
                    'id' => $class->id,
                    'name' => $class->name,
                    'grade_level' => $class->grade_level,
                    'homeroom_teacher' => $class->homeroom_teacher,
                    'is_active' => (bool) $class->is_active,
                    'total_students' => (int) $total,
                ];
            })
            ->values();

        return [
            'total' => $classes->count(),
            'classes' => $classes,
        ];
    }

    public function getSubjects(User $user): array
    {
        $schoolId = $user->school_id;

        $subjects = DB::table('subjects')
            ->where('school_id', $schoolId)
            ->select('id', 'code', 'name', 'grade_level', 'school_level', 'is_active')
            ->orderBy('name')
            ->get()
            ->map(fn ($subject) => [
                'id' => $subject->id,
                'code' => $subject->code,
                'name' => $subject->name,
                'grade_level' => $subject->grade_level,
                'school_level' => $subject->school_level,
                'is_active' => (bool) $subject->is_active,
            ])
            ->values();

        return [
            'total' => $subjects->count(),
            'subjects' => $subjects,
        ];
    }

    public function getSchedules(User $user): array
    {
        $schoolId = $user->school_id;

        $schedules = DB::table('schedules')
            ->leftJoin('classes', 'schedules.class_id', '=', 'classes.id')
            ->leftJoin('subjects', 'schedules.subject_id', '=', 'subjects.id')
            ->leftJoin('users as teachers', 'schedules.teacher_id', '=', 'teachers.id')
            ->where('schedules.school_id', $schoolId)
            ->select(
                'schedules.id',
                'schedules.day_of_week',
                'schedules.start_time',
                'schedules.end_time',
                'schedules.room',
                'schedules.is_active',
                'classes.name as class_name',
                'subjects.name as subject_name',
                'teachers.name as teacher_name'
            )
            ->orderBy('schedules.day_of_week')
            ->orderBy('schedules.start_time')
            ->get()
            ->map(fn ($schedule) => [
                'id' => $schedule->id,
                'day_of_week' => $schedule->day_of_week,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
                'room' => $schedule->room,
                'is_active' => (bool) $schedule->is_active,
                'class_name' => $schedule->class_name,
                'subject_name' => $schedule->subject_name,
                'teacher_name' => $schedule->teacher_name,
            ])
            ->values();

        return [
            'total' => $schedules->count(),
            'schedules' => $schedules,
        ];
    }

    public function getParents(User $user): array
    {
        $schoolId = $user->school_id;

        $parents = DB::table('users')
            ->where('school_id', $schoolId)
            ->where('role_type', 'parent')
            ->select('id', 'name', 'username', 'email', 'is_active')
            ->orderBy('name')
            ->get()
            ->map(fn ($parent) => [
                'id' => $parent->id,
                'name' => $parent->name,
                'username' => $parent->username,
                'email' => $parent->email,
                'is_active' => (bool) $parent->is_active,
            ])
            ->values();

        return [
            'total' => $parents->count(),
            'parents' => $parents,
        ];
    }

    public function getReports(User $user): array
    {
        $schoolId = $user->school_id;

        $reports = DB::table('attendance_reports')
            ->where('school_id', $schoolId)
            ->orderBy('report_date', 'desc')
            ->limit(50)
            ->select(
                'id',
                'report_type',
                'report_date',
                'period_start',
                'period_end',
                'attendance_rate',
                'file_url'
            )
            ->get()
            ->map(fn ($report) => [
                'id' => $report->id,
                'report_type' => $report->report_type,
                'report_date' => $report->report_date,
                'period_start' => $report->period_start,
                'period_end' => $report->period_end,
                'attendance_rate' => $report->attendance_rate,
                'file_url' => $report->file_url,
            ])
            ->values();

        return [
            'total' => $reports->count(),
            'reports' => $reports,
        ];
    }

    public function getSchoolProfile(User $user): array
    {
        $school = DB::table('schools')
            ->where('id', $user->school_id)
            ->first();

        if (! $school) {
            return [];
        }

        return [
            'id' => $school->id,
            'name' => $school->name,
            'npsn' => $school->npsn,
            'school_level' => $school->school_level,
            'address' => $school->address,
            'phone' => $school->phone,
            'email' => $school->email,
            'timezone' => $school->timezone,
            'logo_url' => $school->logo_url,
            'latitude' => $school->latitude,
            'longitude' => $school->longitude,
            'radius_meters' => $school->radius_meters,
            'settings' => \App\Helpers\SchoolSettingsHelper::getSettingsWithCache($user->school_id),
            'is_active' => (bool) $school->is_active,
        ];
    }

    public function getAcademicYears(User $user): array
    {
        $schoolId = $user->school_id;

        $years = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->orderByDesc('is_active')
            ->orderByDesc('start_date')
            ->get([
                'id',
                'name',
                'semester',
                'start_date',
                'end_date',
                'is_active',
            ])
            ->map(fn ($year) => [
                'id' => $year->id,
                'name' => $year->name,
                'semester' => $year->semester,
                'start_date' => $year->start_date,
                'end_date' => $year->end_date,
                'is_active' => (bool) $year->is_active,
            ])
            ->values();

        return [
            'total' => $years->count(),
            'years' => $years,
        ];
    }

    public function getTeacherAssignments(User $user): array
    {
        $schoolId = $user->school_id;

        $assignments = DB::table('teacher_subjects')
            ->join('users as teachers', 'teacher_subjects.teacher_id', '=', 'teachers.id')
            ->join('subjects', 'teacher_subjects.subject_id', '=', 'subjects.id')
            ->join('classes', 'teacher_subjects.class_id', '=', 'classes.id')
            ->join('academic_years', 'teacher_subjects.academic_year_id', '=', 'academic_years.id')
            ->where('teachers.school_id', $schoolId)
            ->select(
                'teacher_subjects.id',
                'teacher_subjects.teacher_id',
                'teacher_subjects.subject_id',
                'teacher_subjects.class_id',
                'teacher_subjects.academic_year_id',
                'teachers.name as teacher_name',
                'subjects.name as subject_name',
                'classes.name as class_name',
                'academic_years.name as academic_year'
            )
            ->orderBy('teachers.name')
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'teacher_id' => $item->teacher_id,
                'subject_id' => $item->subject_id,
                'class_id' => $item->class_id,
                'academic_year_id' => $item->academic_year_id,
                'teacher_name' => $item->teacher_name,
                'subject_name' => $item->subject_name,
                'class_name' => $item->class_name,
                'academic_year' => $item->academic_year,
            ])
            ->values();

        return [
            'total' => $assignments->count(),
            'assignments' => $assignments,
        ];
    }

    public function createTeacherAssignment(User $user, array $data): array
    {
        $schoolId = $user->school_id;
        $teacher = User::where('school_id', $schoolId)
            ->whereIn('role_type', ['teacher', 'homeroom_teacher'])
            ->where('id', $data['teacher_id'])
            ->firstOrFail();

        $classExists = DB::table('classes')
            ->where('school_id', $schoolId)
            ->where('id', $data['class_id'])
            ->exists();

        if (! $classExists) {
            abort(404, 'Kelas tidak ditemukan.');
        }

        $subjectExists = DB::table('subjects')
            ->where('school_id', $schoolId)
            ->where('id', $data['subject_id'])
            ->exists();

        if (! $subjectExists) {
            abort(404, 'Mapel tidak ditemukan.');
        }

        $academicYearId = $data['academic_year_id'] ?? DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->value('id');

        if (! $academicYearId) {
            abort(422, 'Tahun ajaran aktif belum diset.');
        }

        $assignmentId = DB::table('teacher_subjects')->insertGetId([
            'teacher_id' => $teacher->id,
            'subject_id' => $data['subject_id'],
            'class_id' => $data['class_id'],
            'academic_year_id' => $academicYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->getTeacherAssignments($user)['assignments']->firstWhere('id', $assignmentId) ?? [
            'id' => $assignmentId,
            'teacher_id' => $teacher->id,
            'subject_id' => (int) $data['subject_id'],
            'class_id' => (int) $data['class_id'],
            'academic_year_id' => (int) $academicYearId,
        ];
    }

    public function deleteTeacherAssignment(User $user, int $assignmentId): void
    {
        $schoolId = $user->school_id;
        
        // SECURITY: Atomic delete with school_id validation to prevent TOCTOU vulnerability
        $deleted = DB::table('teacher_subjects')
            ->join('classes', 'teacher_subjects.class_id', '=', 'classes.id')
            ->where('teacher_subjects.id', $assignmentId)
            ->where('classes.school_id', $schoolId)
            ->delete();

        if ($deleted === 0) {
            abort(404, 'Mapping tidak ditemukan.');
        }
    }

    public function setHomeroomTeacher(User $user, array $data): array
    {
        $schoolId = $user->school_id;
        $teacher = User::where('school_id', $schoolId)
            ->whereIn('role_type', ['teacher', 'homeroom_teacher'])
            ->where('id', $data['teacher_id'])
            ->firstOrFail();

        $academicYearId = $data['academic_year_id'] ?? DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->value('id');

        if (! $academicYearId) {
            abort(422, 'Tahun ajaran aktif belum diset.');
        }

        if (! empty($data['homeroom_class_id'])) {
            $classExists = DB::table('classes')
                ->where('school_id', $schoolId)
                ->where('id', $data['homeroom_class_id'])
                ->exists();

            if (! $classExists) {
                abort(404, 'Kelas tidak ditemukan.');
            }
        }

        $isHomeroom = $data['is_homeroom_teacher'] ?? ! empty($data['homeroom_class_id']);

        DB::table('teacher_roles')
            ->updateOrInsert(
                [
                    'teacher_id' => $teacher->id,
                    'academic_year_id' => $academicYearId,
                ],
                [
                    'is_homeroom_teacher' => (bool) $isHomeroom,
                    'homeroom_class_id' => $data['homeroom_class_id'] ?? null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

        return [
            'teacher_id' => $teacher->id,
            'is_homeroom_teacher' => (bool) $isHomeroom,
            'homeroom_class_id' => $data['homeroom_class_id'] ?? null,
            'academic_year_id' => $academicYearId,
        ];
    }

    private function assignStudentClass(int $schoolId, int $studentId, ?int $classId): void
    {
        if (! $classId) {
            return;
        }

        $classExists = DB::table('classes')
            ->where('school_id', $schoolId)
            ->where('id', $classId)
            ->exists();

        if (! $classExists) {
            abort(404, 'Kelas tidak ditemukan.');
        }

        // SECURITY: Validate student enrollment belongs to same school
        $existing = DB::table('class_students')
            ->join('classes', 'class_students.class_id', '=', 'classes.id')
            ->where('class_students.student_id', $studentId)
            ->where('class_students.status', 'active')
            ->where('classes.school_id', $schoolId)
            ->select('class_students.id', 'class_students.class_id')
            ->first();

        if ($existing && (int) $existing->class_id === (int) $classId) {
            return;
        }

        if ($existing) {
            DB::table('class_students')
                ->where('id', $existing->id)
                ->update([
                    'status' => 'moved',
                    'updated_at' => now(),
                ]);
        }

        DB::table('class_students')->insert([
            'class_id' => $classId,
            'student_id' => $studentId,
            'enrollment_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function formatTeacher(int $schoolId, User $teacher): array
    {
        $totalSchedules = DB::table('schedules')
            ->where('school_id', $schoolId)
            ->where('teacher_id', $teacher->id)
            ->count();

        return [
            'id' => $teacher->id,
            'name' => $teacher->name,
            'username' => $teacher->username,
            'email' => $teacher->email,
            'role_type' => $teacher->role_type,
            'is_active' => (bool) $teacher->is_active,
            'last_login_at' => $teacher->last_login_at,
            'total_schedules' => (int) $totalSchedules,
        ];
    }

    private function formatStudent(int $schoolId, int $studentId): array
    {
        $student = DB::table('users as students')
            ->leftJoin('class_students', function ($join) {
                $join->on('class_students.student_id', '=', 'students.id')
                    ->where('class_students.status', '=', 'active');
            })
            ->leftJoin('classes', 'class_students.class_id', '=', 'classes.id')
            ->where('students.school_id', $schoolId)
            ->where('students.role_type', 'student')
            ->where('students.id', $studentId)
            ->select(
                'students.id',
                'students.name',
                'students.username',
                'students.email',
                'students.is_active',
                DB::raw('max(classes.name) as class_name'),
                DB::raw('max(class_students.class_id) as class_id')
            )
            ->groupBy('students.id', 'students.name', 'students.username', 'students.email', 'students.is_active')
            ->first();

        return [
            'id' => $student->id ?? $studentId,
            'name' => $student->name ?? null,
            'username' => $student->username ?? null,
            'email' => $student->email ?? null,
            'is_active' => (bool) ($student->is_active ?? true),
            'class_name' => $student->class_name ?? null,
            'class_id' => $student->class_id ?? null,
        ];
    }
}
