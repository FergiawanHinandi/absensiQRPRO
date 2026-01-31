<?php

namespace App\Services\SchoolAdmin;

use App\Models\User;
use App\Traits\HasSchoolLimits;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StudentService
{
    use HasSchoolLimits;

    /**
     * Store a new student
     */
    public function store(array $validated, int $schoolId)
    {
        if (! $this->checkSchoolLimit($schoolId, 'students')) {
            throw new Exception('Batas jumlah siswa tercapai. Silakan upgrade paket sekolah Anda.');
        }

        DB::beginTransaction();
        try {
            $password = $validated['password'] ?? 'password';

            $student = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'username' => $validated['username'],
                'password' => Hash::make($password),
                'role_type' => 'student',
                'school_id' => $schoolId,
                'is_active' => true,
            ]);

            DB::table('user_profiles')->insert([
                'user_id' => $student->id,
                'full_name' => $validated['name'],
                'nisn' => $validated['nisn'] ?? null,
                'gender' => $validated['gender'],
                'birth_date' => $validated['birth_date'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('class_students')->insert([
                'class_id' => $validated['class_id'],
                'student_id' => $student->id,
                'enrollment_date' => now()->toDateString(),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Parent handling (if needed)
            if (! empty($validated['parent_name'])) {
                // Future implementation
            }

            DB::commit();

            return $student;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update Mutation Status
     */
    public function updateMutation(int $studentId, string $status, int $schoolId)
    {
        $active = DB::table('class_students')
            ->join('classes', 'class_students.class_id', '=', 'classes.id')
            ->where('class_students.student_id', $studentId)
            ->where('class_students.status', 'active')
            ->where('classes.school_id', $schoolId)
            ->select('class_students.id')
            ->first();

        if (! $active) {
            throw new Exception('Siswa belum memiliki kelas aktif.');
        }

        DB::table('class_students')
            ->where('id', $active->id)
            ->update([
                'status' => $status,
                'updated_at' => now(),
            ]);

        return true;
    }

    /**
     * Update Student Placement
     */
    public function updatePlacement(int $studentId, int $classId, int $schoolId)
    {
        // Verify class belongs to school
        $classExists = DB::table('classes')
            ->where('school_id', $schoolId)
            ->where('id', $classId)
            ->exists();

        if (! $classExists) {
            throw new Exception('Kelas tidak valid.');
        }

        DB::transaction(function () use ($studentId, $classId, $schoolId) {
            // SECURITY: Validate student enrollment belongs to same school
            $existing = DB::table('class_students')
                ->join('classes', 'class_students.class_id', '=', 'classes.id')
                ->where('class_students.student_id', $studentId)
                ->where('class_students.status', 'active')
                ->where('classes.school_id', $schoolId)
                ->select('class_students.id', 'class_students.class_id')
                ->first();

            if ($existing && (int) $existing->class_id === $classId) {
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
        });

        return true;
    }

    /**
     * Process Import
     */
    public function import(\Illuminate\Http\UploadedFile $file, int $schoolId)
    {
        $rows = $this->parseImportFile($file);

        // Feature: Class Mapping
        $classNames = array_filter(array_column($rows, 'class_name'));
        $classIds = array_filter(array_column($rows, 'class_id'));

        $classMap = [];
        if (! empty($classNames)) {
            $classMap = DB::table('classes')
                ->where('school_id', $schoolId)
                ->whereIn('name', array_map('trim', $classNames))
                ->pluck('id', 'name')
                ->mapWithKeys(fn ($id, $name) => [strtolower($name) => $id])
                ->toArray();
        }

        $classIdMap = [];
        if (! empty($classIds)) {
            $classIdMap = DB::table('classes')
                ->where('school_id', $schoolId)
                ->whereIn('id', $classIds)
                ->pluck('id', 'id')
                ->toArray();
        }

        // Validation & Preparation
        $emails = array_filter(array_column($rows, 'email'));
        $existingEmails = User::whereIn('email', $emails)->pluck('email')->toArray();
        $existingNis = User::where('school_id', $schoolId)
            ->whereIn('nis', array_filter(array_column($rows, 'nis')))
            ->pluck('nis')
            ->toArray();

        $validRows = [];
        $errors = [];
        $rowIndex = 1;

        foreach ($rows as $data) {
            $rowIndex++;
            $rowError = null;

            $name = trim($data['name'] ?? '');
            $email = trim($data['email'] ?? '');
            $nis = trim($data['nis'] ?? '');
            $gender = strtoupper(trim($data['gender'] ?? ''));
            $classIdInput = trim($data['class_id'] ?? '');
            $classNameInput = trim($data['class_name'] ?? '');

            if ($name === '' || $email === '' || $nis === '' || ! in_array($gender, ['L', 'P'])) {
                $rowError = 'Kolom name, email, nis, gender (L/P) wajib diisi.';
            } elseif (in_array($email, $existingEmails)) {
                $rowError = 'Email sudah terdaftar.';
            } elseif (in_array($nis, $existingNis)) {
                $rowError = 'NIS sudah terdaftar.';
            }

            $resolvedClassId = null;
            if (! $rowError) {
                if ($classIdInput !== '' && isset($classIdMap[$classIdInput])) {
                    $resolvedClassId = $classIdMap[$classIdInput];
                } elseif ($classNameInput !== '' && isset($classMap[strtolower($classNameInput)])) {
                    $resolvedClassId = $classMap[strtolower($classNameInput)];
                } else {
                    $rowError = 'Kelas tidak ditemukan.';
                }
            }

            if ($rowError) {
                $errors[] = ['row' => $rowIndex, 'message' => $rowError];

                continue;
            }

            $data['class_id'] = $resolvedClassId;
            $data['password'] = trim($data['password'] ?? '') ?: 'password';
            $data['nisn'] = trim($data['nisn'] ?? '') ?: null;
            $validRows[] = $data;

            $existingEmails[] = $email;
            $existingNis[] = $nis;
        }

        if (empty($validRows)) {
            throw new Exception('Tidak ada data valid yang dapat diimpor. Errors: '.json_encode($errors));
        }

        // Limit Check
        // Using trait logic but customized for count trigger
        // Trait logic returns bool based on CURRENT count, doesn't add new count.
        // I need to check (current + new)
        $school = \App\Models\School::find($schoolId);
        $isPremium = $school && $school->package_type === 'premium';
        $maxStudents = $school ? $school->max_students : 0;
        $currentStudents = User::where('school_id', $schoolId)->where('role_type', 'student')->count();

        if (! $isPremium && $maxStudents > 0 && ($currentStudents + count($validRows)) > $maxStudents) {
            throw new Exception("Impor gagal. Total siswa akan melebihi batas paket ({$maxStudents}). Upgrade paket Anda.");
        }

        try {
            return $this->bulkInsertStudents($validRows, $schoolId);
        } catch (Exception $e) {
            throw new Exception('Terjadi kesalahan saat menyimpan data: '.$e->getMessage());
        }
    }

    private function bulkInsertStudents(array $rows, int $schoolId): int
    {
        DB::beginTransaction();
        try {
            $now = now();
            $totalCreated = 0;
            $chunks = array_chunk($rows, 500);

            foreach ($chunks as $chunk) {
                $usersData = [];
                $emailsInChunk = [];

                foreach ($chunk as $row) {
                    $usersData[] = [
                        'school_id' => $schoolId,
                        'name' => $row['name'],
                        'email' => $row['email'],
                        'username' => $row['nis'],
                        'password' => Hash::make($row['password']),
                        'role_type' => 'student',
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $emailsInChunk[] = $row['email'];
                }

                User::insert($usersData);
                $totalCreated += count($usersData);

                $usersMap = User::whereIn('email', $emailsInChunk)
                    ->pluck('id', 'email')
                    ->toArray();

                $profileData = [];
                $pivotData = [];

                foreach ($chunk as $row) {
                    if (isset($usersMap[$row['email']])) {
                        $userId = $usersMap[$row['email']];

                        $profileData[] = [
                            'user_id' => $userId,
                            'full_name' => $row['name'],
                            'nisn' => $row['nisn'] ?: null,
                            'gender' => strtoupper($row['gender']) === 'L' ? 'male' : 'female',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        $pivotData[] = [
                            'student_id' => $userId,
                            'class_id' => $row['class_id'],
                            'enrollment_date' => $now->toDateString(),
                            'status' => 'active',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if (! empty($profileData)) {
                    DB::table('user_profiles')->insert($profileData);
                }
                if (! empty($pivotData)) {
                    DB::table('class_students')->insert($pivotData);
                }
            }

            DB::commit();

            return $totalCreated;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function parseImportFile(\Illuminate\Http\UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if (! $handle) {
            throw new Exception('File tidak dapat dibaca');
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);
            throw new Exception('Header CSV tidak ditemukan');
        }

        $headerMap = array_map(fn ($item) => strtolower(trim($item)), $header);
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) === 1 && trim($row[0]) === '') {
                continue;
            }

            $data = [];
            foreach ($headerMap as $i => $key) {
                $data[$key] = $row[$i] ?? null;
            }
            $rows[] = $data;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Update Student
     */
    public function update(int $studentId, array $validated, int $schoolId)
    {
        $student = User::where('id', $studentId)->where('school_id', $schoolId)->firstOrFail();

        DB::beginTransaction();
        try {
            // Gender Normalization
            $gender = $validated['gender'];
            if (in_array(strtoupper($gender), ['L', 'P'])) {
                $gender = (strtoupper($gender) === 'L') ? 'male' : 'female';
            }

            $userUpdate = [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'username' => $validated['nis'], // NIS mapped to username
                'is_active' => true,
            ];

            if (! empty($validated['password'])) {
                $userUpdate['password'] = Hash::make($validated['password']);
            }

            $student->update($userUpdate);

            // Update Profile
            DB::table('user_profiles')->updateOrInsert(
                ['user_id' => $student->id],
                [
                    'full_name' => $validated['name'],
                    'nisn' => $validated['nisn'] ?? null,
                    'gender' => $gender,
                    'birth_date' => $validated['birth_date'] ?? null,
                    'phone' => $validated['phone'] ?? null,
                    'address' => $validated['address'] ?? null,
                    'updated_at' => now(),
                ]
            );

            // Update Class if changed
            if (isset($validated['class_id'])) {
                $currentClass = DB::table('class_students')
                    ->where('student_id', $student->id)
                    ->where('status', 'active')
                    ->first();

                if (! $currentClass || $currentClass->class_id != $validated['class_id']) {
                    if ($currentClass) {
                        DB::table('class_students')
                            ->where('id', $currentClass->id)
                            ->update(['status' => 'moved', 'updated_at' => now()]);
                    }

                    DB::table('class_students')->insert([
                        'student_id' => $student->id,
                        'class_id' => $validated['class_id'],
                        'status' => 'active',
                        'enrollment_date' => now()->toDateString(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::commit();

            return $student;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
