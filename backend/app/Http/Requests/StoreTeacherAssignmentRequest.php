<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTeacherAssignmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasAnyRole(['school_admin', 'principal']);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'teacher_id' => [
                'required',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    $teacher = \App\Models\User::where('id', $value)
                        ->where('school_id', $this->user()->school_id)
                        ->whereIn('role_type', ['teacher', 'homeroom_teacher'])
                        ->first();

                    if (! $teacher) {
                        $fail('Guru tidak valid atau tidak terdaftar di sekolah ini.');
                    }
                },
            ],
            'subject_id' => [
                'required',
                'exists:subjects,id',
                function ($attribute, $value, $fail) {
                    $subject = \App\Models\Subject::where('id', $value)
                        ->where('school_id', $this->user()->school_id)
                        ->first();

                    if (! $subject) {
                        $fail('Mata pelajaran tidak valid atau tidak terdaftar di sekolah ini.');
                    }
                },
            ],
            'class_id' => [
                'required',
                'exists:classes,id',
                function ($attribute, $value, $fail) {
                    $class = \Illuminate\Support\Facades\DB::table('classes')
                        ->where('id', $value)
                        ->where('school_id', $this->user()->school_id)
                        ->first();

                    if (! $class) {
                        $fail('Kelas tidak valid atau tidak terdaftar di sekolah ini.');
                    }
                },
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
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'teacher_id.required' => 'Guru harus dipilih.',
            'teacher_id.exists' => 'Guru yang dipilih tidak valid.',
            'subject_id.required' => 'Mata pelajaran harus dipilih.',
            'subject_id.exists' => 'Mata pelajaran yang dipilih tidak valid.',
            'class_id.required' => 'Kelas harus dipilih.',
            'class_id.exists' => 'Kelas yang dipilih tidak valid.',
            'academic_year_id.exists' => 'Tahun ajaran yang dipilih tidak valid.',
        ];
    }
}
