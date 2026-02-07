<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class UserManagementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasRole('super_admin');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'role_type' => 'required|in:super_admin,school_admin,principal,vice_principal,teacher,homeroom_teacher,staff,student,parent',
            'school_id' => 'nullable|exists:schools,id',
            'is_active' => 'sometimes|boolean',
            'password' => 'sometimes|string|min:8|confirmed',
        ];

        // For updates, make email unique except for current user
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $userId = $this->route('user');
            $rules['email'] = "required|email|max:255|unique:users,email,{$userId}";
            $rules['password'] = 'sometimes|nullable|string|min:8|confirmed';
        } else {
            $rules['email'] = 'required|email|max:255|unique:users,email';
            $rules['password'] = 'required|string|min:8|confirmed';
        }

        // School admin and below require school_id
        if (in_array($this->input('role_type'), ['school_admin', 'principal', 'vice_principal', 'teacher', 'homeroom_teacher', 'staff', 'student', 'parent'])) {
            $rules['school_id'] = 'required|exists:schools,id';
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama pengguna wajib diisi.',
            'email.required' => 'Email wajib diisi.',
            'email.unique' => 'Email sudah digunakan.',
            'role_type.required' => 'Jenis peran wajib dipilih.',
            'role_type.in' => 'Jenis peran tidak valid.',
            'school_id.required' => 'Sekolah wajib dipilih untuk peran ini.',
            'school_id.exists' => 'Sekolah tidak valid.',
            'password.required' => 'Password wajib diisi.',
            'password.min' => 'Password minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
        ];
    }
}