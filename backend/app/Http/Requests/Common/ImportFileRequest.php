<?php

namespace App\Http\Requests\Common;

use Illuminate\Foundation\Http\FormRequest;

class ImportFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && in_array($this->user()->role_type, ['admin', 'school_admin', 'super_admin']);
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:csv,txt,xlsx',
                'max:2048', // 2MB max
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'File wajib dipilih.',
            'file.file' => 'File tidak valid.',
            'file.mimes' => 'File harus berformat CSV, TXT, atau XLSX.',
            'file.max' => 'Ukuran file maksimal 2MB.',
        ];
    }
}
