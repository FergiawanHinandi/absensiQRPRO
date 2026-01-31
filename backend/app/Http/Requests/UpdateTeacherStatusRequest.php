<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTeacherStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasAnyRole(['school_admin', 'principal']);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        // No validation rules needed as this is a toggle action
        // The teacher ID comes from route parameter
        return [];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $teacherId = $this->route('teacherId');

            if (! $teacherId) {
                $validator->errors()->add('teacher_id', 'ID guru tidak valid.');

                return;
            }

            $teacher = \App\Models\User::where('id', $teacherId)
                ->where('school_id', $this->user()->school_id)
                ->whereIn('role_type', ['teacher', 'homeroom_teacher'])
                ->first();

            if (! $teacher) {
                $validator->errors()->add('teacher_id', 'Guru tidak ditemukan atau tidak terdaftar di sekolah ini.');
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'teacher_id' => 'Guru tidak valid.',
        ];
    }
}
