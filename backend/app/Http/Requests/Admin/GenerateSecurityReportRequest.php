<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateSecurityReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        
        // Only super_admin or school_admin can generate reports
        return $user && in_array($user->role_type, ['super_admin', 'admin', 'school_admin']);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'teacher_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
            'range' => [
                'sometimes',
                'string',
                Rule::in(['7d', '14d', '30d']),
            ],
        ];
    }

    /**
     * Get custom messages for validation errors.
     */
    public function messages(): array
    {
        return [
            'teacher_id.required' => 'Teacher ID is required.',
            'teacher_id.exists' => 'The specified teacher does not exist.',
            'range.in' => 'Range must be one of: 7d, 14d, 30d.',
        ];
    }
}
