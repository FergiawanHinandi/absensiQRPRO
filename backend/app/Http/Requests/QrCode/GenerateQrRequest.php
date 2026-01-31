<?php

namespace App\Http\Requests\QrCode;

use Illuminate\Foundation\Http\FormRequest;

class GenerateQrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()->role_type, ['teacher', 'homeroom_teacher', 'admin', 'school_admin']);
    }

    public function rules(): array
    {
        return [
            'schedule_id' => 'required|exists:schedules,id',
            'qr_type' => 'required|in:in,out',
            'valid_minutes' => 'nullable|integer|min:1|max:60',
            'max_scans' => 'nullable|integer|min:1|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'schedule_id.required' => 'Jadwal wajib dipilih.',
            'schedule_id.exists' => 'Jadwal tidak ditemukan.',
            'qr_type.required' => 'Tipe QR wajib dipilih.',
            'qr_type.in' => 'Tipe QR tidak valid (in/out).',
            'valid_minutes.integer' => 'Durasi valid harus berupa angka.',
            'valid_minutes.min' => 'Durasi minimal 1 menit.',
            'valid_minutes.max' => 'Durasi maksimal 60 menit.',
            'max_scans.integer' => 'Maksimal scan harus berupa angka.',
            'max_scans.min' => 'Maksimal scan minimal 1.',
            'max_scans.max' => 'Maksimal scan maksimal 100.',
        ];
    }
}
