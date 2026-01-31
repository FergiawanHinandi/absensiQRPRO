<?php

namespace App\Http\Requests\SchoolAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasAnyRole(['school_admin', 'principal', 'vice_principal']);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $studentId = $this->route('studentId');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-zA-Z\s\.\,\-\']+$/',
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users')->ignore($studentId),
                function ($attribute, $value, $fail) {
                    // Check if email belongs to different school
                    $existingUser = \App\Models\User::where('email', $value)
                        ->where('id', '!=', $this->route('studentId'))
                        ->where('school_id', '!=', $this->user()->school_id)
                        ->first();

                    if ($existingUser) {
                        $fail('Email sudah digunakan oleh sekolah lain.');
                    }
                },
            ],
            'nis' => [
                'required',
                'string',
                'max:20',
                'regex:/^[0-9]+$/',
                function ($attribute, $value, $fail) {
                    // Check NIS uniqueness within school
                    $exists = \App\Models\User::where('nis', $value)
                        ->where('school_id', $this->user()->school_id)
                        ->where('role_type', 'student')
                        ->where('id', '!=', $this->route('studentId'))
                        ->exists();

                    if ($exists) {
                        $fail('NIS sudah digunakan oleh siswa lain di sekolah ini.');
                    }
                },
            ],
            'nisn' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^[0-9]+$/',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        // Check NISN uniqueness globally
                        $exists = \App\Models\User::where('nisn', $value)
                            ->where('role_type', 'student')
                            ->where('id', '!=', $this->route('studentId'))
                            ->exists();

                        if ($exists) {
                            $fail('NISN sudah digunakan oleh siswa lain.');
                        }
                    }
                },
            ],
            'gender' => [
                'required',
                'in:L,P',
            ],
            'class_id' => [
                'required',
                'exists:classes,id',
                function ($attribute, $value, $fail) {
                    $class = \Illuminate\Support\Facades\DB::table('classes')
                        ->where('id', $value)
                        ->where('school_id', $this->user()->school_id)
                        ->where('is_active', true)
                        ->first();

                    if (! $class) {
                        $fail('Kelas tidak valid atau tidak aktif di sekolah ini.');
                    }
                },
            ],
            'password' => [
                'nullable',
                'string',
                'min:6',
                'max:255',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama siswa harus diisi.',
            'name.string' => 'Nama siswa harus berupa teks.',
            'name.max' => 'Nama siswa maksimal 255 karakter.',
            'name.regex' => 'Nama siswa hanya boleh mengandung huruf, spasi, titik, koma, tanda hubung, dan apostrof.',

            'email.required' => 'Email harus diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.max' => 'Email maksimal 255 karakter.',
            'email.unique' => 'Email sudah digunakan.',

            'nis.required' => 'NIS harus diisi.',
            'nis.string' => 'NIS harus berupa teks.',
            'nis.max' => 'NIS maksimal 20 karakter.',
            'nis.regex' => 'NIS hanya boleh mengandung angka.',

            'nisn.string' => 'NISN harus berupa teks.',
            'nisn.max' => 'NISN maksimal 20 karakter.',
            'nisn.regex' => 'NISN hanya boleh mengandung angka.',

            'gender.required' => 'Jenis kelamin harus dipilih.',
            'gender.in' => 'Jenis kelamin harus L (Laki-laki) atau P (Perempuan).',

            'class_id.required' => 'Kelas harus dipilih.',
            'class_id.exists' => 'Kelas yang dipilih tidak valid.',

            'password.string' => 'Password harus berupa teks.',
            'password.min' => 'Password minimal 6 karakter.',
            'password.max' => 'Password maksimal 255 karakter.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama siswa',
            'email' => 'email',
            'nis' => 'NIS',
            'nisn' => 'NISN',
            'gender' => 'jenis kelamin',
            'class_id' => 'kelas',
            'password' => 'password',
        ];
    }
}
