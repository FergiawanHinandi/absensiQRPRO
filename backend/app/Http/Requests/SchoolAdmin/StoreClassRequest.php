<?php

namespace App\Http\Requests\SchoolAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && in_array($this->user()->role_type, ['admin', 'school_admin', 'super_admin']);
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('classes')->where(function ($query) {
                    return $query->where('school_id', $this->user()->school_id);
                }),
            ],
            'grade_level' => 'required|integer|min:1|max:12',
            'capacity' => 'required|integer|min:1|max:50',
            'homeroom_teacher_id' => 'nullable|exists:users,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'description' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama kelas wajib diisi.',
            'name.unique' => 'Nama kelas sudah digunakan di sekolah ini.',
            'grade_level.required' => 'Tingkat kelas wajib diisi.',
            'grade_level.min' => 'Tingkat kelas minimal 1.',
            'grade_level.max' => 'Tingkat kelas maksimal 12.',
            'capacity.required' => 'Kapasitas kelas wajib diisi.',
            'capacity.min' => 'Kapasitas minimal 1 siswa.',
            'capacity.max' => 'Kapasitas maksimal 50 siswa.',
            'homeroom_teacher_id.exists' => 'Wali kelas tidak ditemukan.',
            'academic_year_id.required' => 'Tahun ajaran wajib dipilih.',
            'academic_year_id.exists' => 'Tahun ajaran tidak ditemukan.',
        ];
    }
}
