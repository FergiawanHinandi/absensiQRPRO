<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\StudentResource;
use App\Models\AcademicYear;
use App\Models\User;
use App\Models\UserProfile;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

class CreateStudent extends CreateRecord
{
    protected static string $resource = StudentResource::class;

    protected ?int $classId = null;

    protected ?string $nisn = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Extract class_id dan nisn sebelum form disimpan ke User
        $this->classId = $data['class_id'] ?? null;
        $this->nisn = $data['nisn'] ?? null;

        unset($data['class_id'], $data['nisn']);

        $data['role_type'] = 'student';
        return $data;
    }

    protected function afterCreate(): void
    {
        $student = $this->record;

        if (! ($student instanceof User)) {
            return;
        }

        // 1. Assign role
        $student->assignRole('student');

        // 2. Simpan NISN ke UserProfile
        if (! empty($this->nisn)) {
            UserProfile::updateOrCreate(
                ['user_id' => $student->id],
                [
                    'full_name' => $student->name,
                    'nisn' => $this->nisn,
                    'gender' => $student->profile?->gender ?? 'male',
                ]
            );
        } else {
            // Tetap buat profile jika belum ada
            UserProfile::firstOrCreate(
                ['user_id' => $student->id],
                [
                    'full_name' => $student->name,
                    'gender' => 'male',
                ]
            );
        }

        // 3. Simpan class_id ke pivot table class_students
        if (! empty($this->classId)) {
            $activeAcademicYear = AcademicYear::where('school_id', $student->school_id)
                ->where('is_active', true)
                ->first();

            DB::table('class_students')->updateOrInsert(
                [
                    'student_id' => $student->id,
                    'class_id' => $this->classId,
                ],
                [
                    'status' => 'active',
                    'enrollment_date' => $activeAcademicYear?->start_date ?? now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
