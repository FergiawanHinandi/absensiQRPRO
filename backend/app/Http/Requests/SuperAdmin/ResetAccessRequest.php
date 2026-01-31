<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class ResetAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role_type === 'super_admin';
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|exists:users,id',
            'type' => 'required|in:password,account',
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'User ID wajib diisi.',
            'user_id.exists' => 'User tidak ditemukan.',
            'type.required' => 'Tipe reset wajib dipilih.',
            'type.in' => 'Tipe reset tidak valid.',
        ];
    }
}
