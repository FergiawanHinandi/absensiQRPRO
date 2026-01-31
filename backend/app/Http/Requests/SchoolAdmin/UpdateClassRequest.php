<?php

namespace App\Http\Requests\SchoolAdmin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClassRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (! auth()->check() || ! $user->hasAnyRole(['school_admin', 'principal', 'vice_principal'])) {
            return false;
        }

        $classId = $this->route('classId');

        return \Illuminate\Support\Facades\DB::table('classes')
            ->where('id', $classId)
            ->where('school_id', $user->school_id)
            ->exists();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $classId = $this->route('classId');

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-zA-Z0-9\s\-\.]+$/',
                function ($attribute, $value, $fail) use ($classId) {
                    // Check name uniqueness within school and academic year
                    $academicYearId = $this->input('academic_year_id');

                    if (! $academicYearId) {
                        // Get current academic year if not provided
                        $academicYearId = \Illuminate\Support\Facades\DB::table('academic_years')
                            ->where('school_id', $this->user()->school_id)
                            ->where('is_active', true)
                            ->value('id');
                    }

                    if ($academicYearId) {
                        $exists = \Illuminate\Support\Facades\DB::table('classes')
                            ->where('school_id', $this->user()->school_id)
                            ->where('academic_year_id', $academicYearId)
                            ->where('name', $value)
                            ->where('id', '!=', $classId)
                            ->exists();

                        if ($exists) {
                            $fail('Nama kelas sudah digunakan pada tahun ajaran ini.');
                        }
                    }
                },
            ],
            'grade_level' => [
                'required',
                'integer',
                'min:1',
                'max:12',
            ],
            'academic_year_id' => [
                'nullable',
                'exists:academic_years,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $academicYear = \App\Models\AcademicYear::where('id', $value)
                            ->where('school_id', $this->user()->school_id)
                            ->first();

                        if (! $academicYear) {
                            $fail('Tahun ajaran tidak valid atau tidak terdaftar di sekolah ini.');
                        }
                    }
                },
            ],
            'homeroom_teacher_id' => [
                'nullable',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $teacher = \App\Models\User::where('id', $value)
                            ->where('school_id', $this->user()->school_id)
                            ->whereIn('role_type', ['teacher', 'homeroom_teacher'])
                            ->where('is_active', true)
                            ->first();

                        if (! $teacher) {
                            $fail('Wali kelas tidak valid, tidak aktif, atau tidak terdaftar di sekolah ini.');
                        }
                    }
                },
            ],
            'max_students' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
            'classroom' => [
                'nullable',
                'string',
                'max:100',
                'regex:/^[a-zA-Z0-9\s\-\.\/]+$/',
            ],
            'is_active' => [
                'nullable',
                'boolean',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama kelas harus diisi.',
            'name.string' => 'Nama kelas harus berupa teks.',
            'name.max' => 'Nama kelas maksimal 100 karakter.',
            'name.regex' => 'Nama kelas hanya boleh mengandung huruf, angka, spasi, tanda hubung, dan titik.',

            'grade_level.required' => 'Tingkat kelas harus diisi.',
            'grade_level.integer' => 'Tingkat kelas harus berupa angka.',
            'grade_level.min' => 'Tingkat kelas minimal 1.',
            'grade_level.max' => 'Tingkat kelas maksimal 12.',

            'academic_year_id.exists' => 'Tahun ajaran yang dipilih tidak valid.',

            'homeroom_teacher_id.exists' => 'Wali kelas yang dipilih tidak valid.',

            'max_students.integer' => 'Maksimal siswa harus berupa angka.',
            'max_students.min' => 'Maksimal siswa minimal 1.',
            'max_students.max' => 'Maksimal siswa maksimal 100.',

            'classroom.string' => 'Ruang kelas harus berupa teks.',
            'classroom.max' => 'Ruang kelas maksimal 100 karakter.',
            'classroom.regex' => 'Ruang kelas hanya boleh mengandung huruf, angka, spasi, tanda hubung, titik, dan garis miring.',

            'is_active.boolean' => 'Status aktif harus berupa true atau false.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama kelas',
            'grade_level' => 'tingkat kelas',
            'academic_year_id' => 'tahun ajaran',
            'homeroom_teacher_id' => 'wali kelas',
            'max_students' => 'maksimal siswa',
            'classroom' => 'ruang kelas',
            'is_active' => 'status aktif',
        ];
    }
}
