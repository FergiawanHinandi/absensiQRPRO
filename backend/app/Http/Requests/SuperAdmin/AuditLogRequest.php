<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class AuditLogRequest extends FormRequest
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
            'search' => 'sometimes|string|max:255',
            'action' => 'sometimes|string|max:100',
            'module' => 'sometimes|string|max:50',
            'user_id' => 'sometimes|integer|exists:users,id',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'ip_address' => 'sometimes|ip',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'search.max' => 'Pencarian maksimal 255 karakter',
            'action.max' => 'Aksi maksimal 100 karakter',
            'module.max' => 'Modul maksimal 50 karakter',
            'user_id.exists' => 'User tidak ditemukan',
            'per_page.max' => 'Maksimal 100 item per halaman',
            'end_date.after_or_equal' => 'Tanggal akhir harus setelah atau sama dengan tanggal mulai',
            'ip_address.ip' => 'Format IP address tidak valid',
        ];
    }
}