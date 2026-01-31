<?php

namespace App\Http\Requests\Permission;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePermissionStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()->role_type, ['teacher', 'homeroom_teacher', 'admin', 'school_admin']);
    }

    public function rules(): array
    {
        return [
            'status' => 'required|in:approved,rejected',
            'notes' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Status wajib dipilih.',
            'status.in' => 'Status tidak valid (approved/rejected).',
            'notes.max' => 'Catatan maksimal 500 karakter.',
        ];
    }
}
