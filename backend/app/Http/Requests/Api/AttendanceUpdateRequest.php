<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * AttendanceUpdateRequest
 *
 * Validates attendance update/correction requests.
 *
 * RESPONSIBILITIES:
 * - Validate status change
 * - Validate notes
 * - Authorize based on policy
 */
class AttendanceUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is also checked via Policy in controller.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (!$user) {
            return false;
        }

        // Basic role check - detailed permission in Policy
        return in_array($user->role_type, [
            'teacher',
            'homeroom_teacher',
            'admin',
            'school_admin',
            'super_admin',
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                'in:present,late,absent,sick,permit,excused',
            ],
            'notes' => ['nullable', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Status absensi diperlukan.',
            'status.in' => 'Status absensi tidak valid. Pilih: present, late, absent, sick, permit, excused.',
            'notes.max' => 'Catatan maksimal 500 karakter.',
            'reason.max' => 'Alasan maksimal 500 karakter.',
        ];
    }
}
