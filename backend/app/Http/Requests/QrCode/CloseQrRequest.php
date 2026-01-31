<?php

namespace App\Http\Requests\QrCode;

use Illuminate\Foundation\Http\FormRequest;

class CloseQrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()->role_type, ['teacher', 'homeroom_teacher', 'admin', 'school_admin']);
    }

    public function rules(): array
    {
        return [
            'qr_code_id' => 'required|exists:qr_codes,id',
        ];
    }

    public function messages(): array
    {
        return [
            'qr_code_id.required' => 'QR Code ID wajib diisi.',
            'qr_code_id.exists' => 'QR Code tidak ditemukan.',
        ];
    }
}
