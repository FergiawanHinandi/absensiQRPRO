<?php

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

class ExportReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()->role_type, ['teacher', 'homeroom_teacher', 'admin', 'school_admin', 'super_admin']);
    }

    public function rules(): array
    {
        return [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'class_id' => 'nullable|exists:classes,id',
            'subject_id' => 'nullable|exists:subjects,id',
            'format' => 'required|in:excel,pdf',
            'include_summary' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'start_date.required' => 'Tanggal mulai wajib diisi.',
            'start_date.date' => 'Format tanggal mulai tidak valid.',
            'end_date.required' => 'Tanggal selesai wajib diisi.',
            'end_date.date' => 'Format tanggal selesai tidak valid.',
            'end_date.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            'class_id.exists' => 'Kelas tidak ditemukan.',
            'subject_id.exists' => 'Mata pelajaran tidak ditemukan.',
            'format.required' => 'Format export wajib dipilih.',
            'format.in' => 'Format export tidak valid (excel/pdf).',
        ];
    }
}
