<?php

namespace App\Http\Requests\SchoolAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && in_array($this->user()->role_type, ['admin', 'school_admin', 'super_admin']);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                Rule::unique('users')->where(function ($query) {
                    return $query->where('school_id', $this->user()->school_id);
                }),
            ],
            'username' => [
                'required',
                'string',
                'max:50',
                'alpha_num',
                Rule::unique('users')->where(function ($query) {
                    return $query->where('school_id', $this->user()->school_id);
                }),
            ],
            'nisn' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('user_profiles')->where(function ($query) {
                    return $query->whereHas('user', function ($q) {
                        $q->where('school_id', $this->user()->school_id);
                    });
                }),
            ],
            'nis' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('user_profiles')->where(function ($query) {
                    return $query->whereHas('user', function ($q) {
                        $q->where('school_id', $this->user()->school_id);
                    });
                }),
            ],
            'class_id' => 'required|exists:classes,id',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'birth_date' => 'nullable|date|before:today',
            'gender' => 'nullable|in:male,female',
            'parent_name' => 'nullable|string|max:255',
            'parent_phone' => 'nullable|string|max:20',
            'parent_email' => 'nullable|email',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama siswa wajib diisi.',
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah digunakan di sekolah ini.',
            'username.required' => 'Username wajib diisi.',
            'username.unique' => 'Username sudah digunakan di sekolah ini.',
            'username.alpha_num' => 'Username hanya boleh huruf dan angka.',
            'nisn.unique' => 'NISN sudah digunakan di sekolah ini.',
            'nis.unique' => 'NIS sudah digunakan di sekolah ini.',
            'class_id.required' => 'Kelas wajib dipilih.',
            'class_id.exists' => 'Kelas tidak ditemukan.',
            'birth_date.before' => 'Tanggal lahir harus sebelum hari ini.',
            'gender.in' => 'Jenis kelamin tidak valid.',
            'parent_email.email' => 'Format email orang tua tidak valid.',
        ];
    }
}
