<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Daily Report Request
 * 
 * Validates daily attendance report parameters
 */
class DailyReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only admins and principals can view reports
        $allowedRoles = ['school_admin', 'admin', 'principal', 'vice_principal', 'super_admin'];
        
        return $this->user() && in_array($this->user()->role_type, $allowedRoles);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'date' => 'nullable|date_format:Y-m-d',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'date.date_format' => 'Format tanggal harus YYYY-MM-DD.',
        ];
    }

    /**
     * Get validated date or default to today
     */
    public function getDate(): string
    {
        return $this->validated()['date'] ?? now()->toDateString();
    }
}
