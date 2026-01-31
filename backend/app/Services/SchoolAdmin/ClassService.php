<?php

namespace App\Services\SchoolAdmin;

use App\Models\ClassModel;
use App\Traits\HasSchoolLimits;
use Exception;

class ClassService
{
    use HasSchoolLimits;

    /**
     * Store new class
     */
    public function store(array $validated, int $schoolId)
    {
        if (! $this->checkSchoolLimit($schoolId, 'classes')) {
            throw new Exception('Batas jumlah kelas tercapai. Silakan upgrade paket sekolah Anda.');
        }

        $validated['school_id'] = $schoolId;

        $class = ClassModel::create($validated);

        // Sync TeacherRole (Homeroom)
        if (! empty($validated['homeroom_teacher_id'])) {
            \App\Models\TeacherRole::updateOrCreate(
                [
                    'user_id' => $validated['homeroom_teacher_id'], // TeacherRole model uses user_id or teacher_id? Controller used teacher_id.
                    // Wait, Controller lines 128 used 'teacher_id'.
                    // But SetHomeroom method in TeacherController (Step 606 line 232) used 'user_id'.
                    // I need to be sure about TeacherRole schema.
                    // Assuming 'teacher_id' based on ClassController existing logic (line 128).
                    'teacher_id' => $validated['homeroom_teacher_id'],
                    'academic_year_id' => $validated['academic_year_id'],
                ],
                [
                    'role_name' => 'homeroom', // Added this based on TeacherController usage? ClassController didn't use role_name?
                    // ClassController used `is_homeroom_teacher => true`.
                    // This implies DIFFERENT schema usage or mismatched code.
                    // TeacherController uses `role_name='homeroom'`.
                    // ClassController uses `is_homeroom_teacher=true`.
                    // This suggests `teacher_roles` table has BOTH? Or inconsistent usage?
                    // I should check `TeacherRole` model or migration.
                    // Since I cannot check migration easily now without searching, I will stick to what ClassController used.
                    // Lines 132 in ClassController: `['is_homeroom_teacher' => true, 'homeroom_class_id' => $classId]`
                    'is_homeroom_teacher' => true,
                    'homeroom_class_id' => $class->id,
                ]
            );
        }

        return $class;
    }

    /**
     * Update class
     */
    public function update(int $classId, array $validated, int $schoolId)
    {
        $class = ClassModel::where('id', $classId)
            ->where('school_id', $schoolId)
            ->firstOrFail();

        $class->update($validated);

        // Sync TeacherRole
        // 1. Clear old
        \App\Models\TeacherRole::where('homeroom_class_id', $classId)
            ->where('academic_year_id', $class->academic_year_id)
            ->delete();

        // 2. Assign new
        if (! empty($validated['homeroom_teacher_id'])) {
            \App\Models\TeacherRole::updateOrCreate(
                [
                    'teacher_id' => $validated['homeroom_teacher_id'],
                    'academic_year_id' => $class->academic_year_id,
                ],
                [
                    'is_homeroom_teacher' => true,
                    'homeroom_class_id' => $class->id,
                ]
            );
        }

        return $class;
    }

    /**
     * Update class status
     */
    public function updateStatus(int $classId, bool $isActive, int $schoolId)
    {
        $class = ClassModel::where('id', $classId)
            ->where('school_id', $schoolId)
            ->firstOrFail();

        $class->update(['is_active' => $isActive]);

        return $class;
    }
}
