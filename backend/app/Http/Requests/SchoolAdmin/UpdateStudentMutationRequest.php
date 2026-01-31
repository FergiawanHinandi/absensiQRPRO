<?php

namespace App\Http\Requests\SchoolAdmin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()->role_type, ['admin', 'school_admin', 'super_admin']);
    }

    public function rules(): array
    {
        return [
            'status' => 'required|in:moved,graduated,dropped',
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Status mutasi wajib diisi.',
            'status.in' => 'Status mutasi tidak valid (pilih: moved, graduated, dropped).',
        ];
    }
}
