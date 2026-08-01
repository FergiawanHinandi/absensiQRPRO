<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Manual Attendance Request
 * 
 * Validates manual attendance input by teacher/admin
 */
class ManualAttendanceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only teachers, homeroom teachers, and admins can input manual attendance
        $allowedRoles = ['teacher', 'homeroom_teacher', 'school_admin', 'admin'];
        
        return $this->user() && in_array($this->user()->role_type, $allowedRoles);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'student_id' => 'required|integer|exists:users,id',
            'schedule_id' => 'required|integer|exists:schedules,id',
            'attendance_date' => 'required|date_format:Y-m-d',
            'status' => 'required|string|in:sick,permit,alpha,excused',
            'notes' => 'nullable|string|max:500',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'student_id.required' => 'Siswa harus dipilih.',
            'student_id.exists' => 'Siswa tidak ditemukan.',
            'schedule_id.required' => 'Jadwal harus dipilih.',
            'schedule_id.exists' => 'Jadwal tidak ditemukan.',
            'attendance_date.required' => 'Tanggal absensi harus diisi.',
            'attendance_date.date_format' => 'Format tanggal harus YYYY-MM-DD.',
            'status.required' => 'Status absensi harus dipilih.',
            'status.in' => 'Status manual hanya boleh: sakit, izin, alpa, atau excused. Untuk hadir gunakan scan QR.',
            'notes.max' => 'Catatan maksimal 500 karakter.',
        ];
    }
}
