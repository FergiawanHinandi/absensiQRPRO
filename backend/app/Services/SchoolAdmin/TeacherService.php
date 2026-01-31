<?php

namespace App\Services\SchoolAdmin;

use App\Models\User;
use App\Traits\HasSchoolLimits;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TeacherService
{
    use HasSchoolLimits;

    /**
     * Store new teacher
     */
    public function store(array $validated, int $schoolId)
    {
        if (! $this->checkSchoolLimit($schoolId, 'teachers')) {
            throw new Exception('Batas jumlah guru tercapai. Silakan upgrade paket sekolah Anda.');
        }

        // Upsert Logic for Teachers (as seen in Part 1)
        // User asked for "Logic in Service".
        // Controller Part 1 used DB::table updateOrInsert. I will do same.

        $existing = User::where('email', $validated['email'])->first();
        if ($existing) {
            if ($existing->school_id == $schoolId && in_array($existing->role_type, ['teacher', 'homeroom_teacher'])) {
                // Restore/Update
                $existing->update([
                    'name' => $validated['name'],
                    'password' => Hash::make($validated['password']),
                    'is_active' => true,
                ]);

                DB::table('user_profiles')->updateOrInsert(
                    ['user_id' => $existing->id],
                    [
                        'full_name' => $validated['name'],
                        'nip' => $validated['nip'],
                        'phone' => $validated['phone'] ?? null,
                        'gender' => $validated['gender'],
                        'updated_at' => now(),
                    ]
                );

                return $existing;
            }

            throw new Exception("Email sudah digunakan oleh '{$existing->name}' (Role: {$existing->role_type}, ID Sekolah: {$existing->school_id}).");
        }

        DB::beginTransaction();
        try {
            // New Teacher
            $username = explode('@', $validated['email'])[0].rand(100, 999);

            $teacher = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'username' => $username,
                'password' => Hash::make($validated['password']),
                'role_type' => 'teacher',
                'school_id' => $schoolId,
                'is_active' => true,
            ]);

            DB::table('user_profiles')->insert([
                'user_id' => $teacher->id,
                'full_name' => $validated['name'],
                'nip' => $validated['nip'],
                'phone' => $validated['phone'] ?? null,
                'gender' => $validated['gender'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return $teacher;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Import Teachers
     */
    public function import(\Illuminate\Http\UploadedFile $file, int $schoolId)
    {
        $rows = $this->parseImportFile($file);

        $emails = array_filter(array_column($rows, 'email'));
        $existingEmails = User::whereIn('email', $emails)->pluck('email')->toArray();

        $validRows = [];
        $errors = [];
        $rowIndex = 1;

        // Limit Check logic manual (current + new)
        $school = \App\Models\School::find($schoolId);
        $isPremium = $school && $school->package_type === 'premium';
        $maxTeachers = $school ? $school->max_teachers : 0;
        $currentTeachers = User::where('school_id', $schoolId)
            ->whereIn('role_type', ['teacher', 'homeroom_teacher'])
            ->count();

        foreach ($rows as $data) {
            $rowIndex++;
            $rowError = null;

            $name = trim($data['name'] ?? '');
            $email = trim($data['email'] ?? '');
            $gender = strtoupper(trim($data['gender'] ?? ''));

            if ($name === '' || $email === '' || ! in_array($gender, ['L', 'P'])) {
                $rowError = 'Kolom name, email, gender (L/P) wajib diisi.';
            } elseif (in_array($email, $existingEmails)) {
                $rowError = 'Email sudah terdaftar.';
            }

            if ($rowError) {
                $errors[] = ['row' => $rowIndex, 'message' => $rowError];

                continue;
            }

            $data['password'] = trim($data['password'] ?? '') ?: 'password';
            $data['nip'] = trim($data['nip'] ?? '') ?: null;
            $data['phone'] = trim($data['phone'] ?? '') ?: null;
            $validRows[] = $data;

            $existingEmails[] = $email;
        }

        if (empty($validRows)) {
            throw new Exception('Tidak ada data valid. Errors: '.json_encode($errors));
        }

        if (! $isPremium && $maxTeachers > 0 && ($currentTeachers + count($validRows)) > $maxTeachers) {
            throw new Exception("Impor gagal. Total akan melebihi batas paket ({$maxTeachers}).");
        }

        try {
            return $this->bulkInsertTeachers($validRows, $schoolId);
        } catch (Exception $e) {
            throw new Exception('Gagal menyimpan data: '.$e->getMessage());
        }
    }

    /**
     * Update Teacher
     */
    public function update(int $teacherId, array $validated, int $schoolId)
    {
        $teacher = User::where('id', $teacherId)
            ->where('school_id', $schoolId)
            ->where('role_type', 'teacher')
            ->firstOrFail();

        DB::beginTransaction();
        try {
            $userUpdate = [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'is_active' => true,
            ];

            if (! empty($validated['password'])) {
                $userUpdate['password'] = Hash::make($validated['password']);
            }

            $teacher->update($userUpdate);

            DB::table('user_profiles')->updateOrInsert(
                ['user_id' => $teacher->id],
                [
                    'full_name' => $validated['name'],
                    'nip' => $validated['nip'],
                    'phone' => $validated['phone'] ?? null,
                    'gender' => $validated['gender'],
                    'updated_at' => now(),
                ]
            );

            DB::commit();

            return $teacher;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function bulkInsertTeachers(array $rows, int $schoolId): int
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
                        'username' => explode('@', $row['email'])[0].rand(100, 999),
                        'password' => Hash::make($row['password']),
                        'role_type' => 'teacher',
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
                foreach ($chunk as $row) {
                    if (isset($usersMap[$row['email']])) {
                        $profileData[] = [
                            'user_id' => $usersMap[$row['email']],
                            'full_name' => $row['name'],
                            'nip' => $row['nip'] ?: null,
                            'phone' => $row['phone'] ?: null,
                            'gender' => strtoupper($row['gender']) === 'L' ? 'male' : 'female',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if (! empty($profileData)) {
                    DB::table('user_profiles')->insert($profileData);
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
}
