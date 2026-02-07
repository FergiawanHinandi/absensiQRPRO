<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class GlobalReportRequest extends FormRequest
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
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'school_ids' => 'sometimes|array',
            'school_ids.*' => 'integer|exists:schools,id',
            'format' => 'sometimes|string|in:json,pdf,excel',
            'include_details' => 'sometimes|boolean',
            'group_by' => 'sometimes|string|in:school,date,class,grade',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'start_date.required' => 'Tanggal mulai wajib diisi',
            'end_date.required' => 'Tanggal akhir wajib diisi',
            'end_date.after_or_equal' => 'Tanggal akhir harus setelah atau sama dengan tanggal mulai',
            'school_ids.array' => 'ID sekolah harus berupa array',
            'school_ids.*.exists' => 'Salah satu sekolah tidak ditemukan',
            'format.in' => 'Format harus salah satu dari: json, pdf, excel',
            'group_by.in' => 'Group by harus salah satu dari: school, date, class, grade',
        ];
    }
}