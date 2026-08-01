<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\StudentResource;
use App\Models\AcademicYear;
use App\Models\User;
use App\Models\UserProfile;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditStudent extends EditRecord
{
    protected static string $resource = StudentResource::class;

    protected ?int $newClassId = null;

    protected ?string $newNisn = null;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Extract class_id dan nisn sebelum form disimpan ke User
        $this->newClassId = $data['class_id'] ?? null;
        $this->newNisn = $data['nisn'] ?? null;

        unset($data['class_id'], $data['nisn']);

        return $data;
    }

    protected function afterSave(): void
    {
        $student = $this->record;

        if (! ($student instanceof User)) {
            return;
        }

        // 1. Update NISN di UserProfile (hanya jika diisi)
        if (! empty($this->newNisn)) {
            UserProfile::updateOrCreate(
                ['user_id' => $student->id],
                [
                    'full_name' => $student->name,
                    'nisn' => $this->newNisn,
                ]
            );
        }

        // 2. Update class_id di pivot table (hanya jika diisi)
        if (! empty($this->newClassId)) {
            // Deactivate old class assignments
            DB::table('class_students')
                ->where('student_id', $student->id)
                ->where('status', 'active')
                ->update(['status' => 'moved', 'updated_at' => now()]);

            // Cari academic year aktif
            $activeAcademicYear = AcademicYear::where('school_id', $student->school_id)
                ->where('is_active', true)
                ->first();

            // Insert new class assignment
            DB::table('class_students')->updateOrInsert(
                [
                    'student_id' => $student->id,
                    'class_id' => $this->newClassId,
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

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
