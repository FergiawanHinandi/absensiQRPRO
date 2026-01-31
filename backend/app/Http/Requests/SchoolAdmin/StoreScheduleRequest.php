<?php

namespace App\Http\Requests\SchoolAdmin;

use Illuminate\Foundation\Http\FormRequest;

class StoreScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()->role_type, ['admin', 'school_admin', 'super_admin']);
    }

    public function rules(): array
    {
        return [
            'class_id' => 'required|exists:classes,id',
            'subject_id' => 'nullable|exists:subjects,id',
            'teacher_id' => 'required|exists:users,id',
            'day_of_week' => 'required|integer|min:0|max:6', // 0=Sunday, 6=Saturday
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'academic_year_id' => 'required|exists:academic_years,id',
            'room' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'class_id.required' => 'Kelas wajib dipilih.',
            'class_id.exists' => 'Kelas tidak ditemukan.',
            'subject_id.exists' => 'Mata pelajaran tidak ditemukan.',
            'teacher_id.required' => 'Guru wajib dipilih.',
            'teacher_id.exists' => 'Guru tidak ditemukan.',
            'day_of_week.required' => 'Hari wajib dipilih.',
            'day_of_week.integer' => 'Hari harus berupa angka.',
            'day_of_week.min' => 'Hari tidak valid.',
            'day_of_week.max' => 'Hari tidak valid.',
            'start_time.required' => 'Waktu mulai wajib diisi.',
            'start_time.date_format' => 'Format waktu mulai tidak valid (HH:MM).',
            'end_time.required' => 'Waktu selesai wajib diisi.',
            'end_time.date_format' => 'Format waktu selesai tidak valid (HH:MM).',
            'end_time.after' => 'Waktu selesai harus setelah waktu mulai.',
            'academic_year_id.required' => 'Tahun ajaran wajib dipilih.',
            'academic_year_id.exists' => 'Tahun ajaran tidak ditemukan.',
            'room.max' => 'Ruangan maksimal 100 karakter.',
            'notes.max' => 'Catatan maksimal 500 karakter.',
        ];
    }
}
