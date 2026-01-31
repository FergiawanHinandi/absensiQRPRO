<?php

namespace App\Http\Requests\SchoolAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()->role_type, ['admin', 'school_admin', 'super_admin']);
    }

    public function rules(): array
    {
        $teacherId = $this->route('teacherId');

        return [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                Rule::unique('users')->ignore($teacherId)->where(function ($query) {
                    return $query->where('school_id', $this->user()->school_id);
                }),
            ],
            'nip' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('user_profiles')->ignore($teacherId, 'user_id')->where(function ($query) {
                    return $query->whereHas('user', function ($q) {
                        $q->where('school_id', $this->user()->school_id);
                    });
                }),
            ],
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'birth_date' => 'nullable|date|before:today',
            'gender' => 'nullable|in:male,female',
            'is_active' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama guru wajib diisi.',
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah digunakan di sekolah ini.',
            'nip.unique' => 'NIP sudah digunakan di sekolah ini.',
            'birth_date.before' => 'Tanggal lahir harus sebelum hari ini.',
            'gender.in' => 'Jenis kelamin tidak valid.',
        ];
    }
}
