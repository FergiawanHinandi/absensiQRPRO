<?php

namespace App\Http\Requests\QrCode;

use Illuminate\Foundation\Http\FormRequest;

class VerifyQrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()->role_type, ['admin', 'school_admin', 'teacher', 'homeroom_teacher']);
    }

    public function rules(): array
    {
        return [
            'qr_token' => 'required|string|min:10',
        ];
    }

    public function messages(): array
    {
        return [
            'qr_token.required' => 'QR Token wajib diisi.',
            'qr_token.string' => 'QR Token harus berupa string.',
            'qr_token.min' => 'QR Token tidak valid (terlalu pendek).',
        ];
    }
}
