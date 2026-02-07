<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class SecurityEventIndexRequest extends FormRequest
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
            'type' => 'sometimes|string|in:login,behavior_anomaly,geofence_violation,device_mismatch',
            'severity' => 'sometimes|string|in:low,medium,high,critical',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'school_id' => 'sometimes|integer|exists:schools,id',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'type.in' => 'Tipe alert harus salah satu dari: login, behavior_anomaly, geofence_violation, device_mismatch',
            'severity.in' => 'Tingkat keparahan harus salah satu dari: low, medium, high, critical',
            'per_page.max' => 'Maksimal 100 item per halaman',
            'end_date.after_or_equal' => 'Tanggal akhir harus setelah atau sama dengan tanggal mulai',
            'school_id.exists' => 'Sekolah tidak ditemukan',
        ];
    }
}