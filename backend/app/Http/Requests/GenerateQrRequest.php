<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateQrRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only teachers can generate QR codes
        return $this->user() && in_array($this->user()->role_type, ['teacher', 'homeroom_teacher']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'schedule_id' => ['required', 'exists:schedules,id'],
            'qr_type' => ['required', 'in:in,out'],
            'expiry_minutes' => ['nullable', 'integer', 'min:5', 'max:60'],
            'max_scans' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'schedule_id.required' => 'Jadwal harus dipilih',
            'schedule_id.exists' => 'Jadwal tidak ditemukan',
            'qr_type.in' => 'Tipe QR harus in (masuk) atau out (keluar)',
        ];
    }
}
