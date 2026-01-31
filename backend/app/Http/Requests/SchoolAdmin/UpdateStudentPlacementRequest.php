<?php

namespace App\Http\Requests\SchoolAdmin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentPlacementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()->role_type, ['admin', 'school_admin', 'super_admin']);
    }

    public function rules(): array
    {
        return [
            'class_id' => 'required|exists:classes,id',
        ];
    }

    public function messages(): array
    {
        return [
            'class_id.required' => 'Kelas wajib dipilih.',
            'class_id.exists' => 'Kelas tidak ditemukan.',
        ];
    }
}
