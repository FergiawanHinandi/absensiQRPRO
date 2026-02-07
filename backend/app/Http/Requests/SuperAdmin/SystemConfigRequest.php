<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class SystemConfigRequest extends FormRequest
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
        return [
            'maintenance_mode' => 'sometimes|boolean',
            'maintenance_message' => 'nullable|string|max:500',
            'max_schools' => 'sometimes|integer|min:1|max:10000',
            'default_timezone' => 'sometimes|string|max:50',
            'backup_enabled' => 'sometimes|boolean',
            'backup_frequency' => 'sometimes|in:daily,weekly,monthly',
            'notification_email' => 'sometimes|email|max:255',
            'system_alerts_enabled' => 'sometimes|boolean',
            'debug_mode' => 'sometimes|boolean',
            'log_level' => 'sometimes|in:emergency,alert,critical,error,warning,notice,info,debug',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'maintenance_message.max' => 'Pesan maintenance maksimal 500 karakter.',
            'max_schools.min' => 'Batas maksimal sekolah minimal 1.',
            'max_schools.max' => 'Batas maksimal sekolah maksimal 10.000.',
            'backup_frequency.in' => 'Frekuensi backup harus salah satu dari: daily, weekly, monthly.',
            'notification_email.email' => 'Format email notifikasi tidak valid.',
            'log_level.in' => 'Level log tidak valid.',
        ];
    }
}