<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManualAttendanceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only teachers and admins can input manual attendance
        return $this->user() && in_array($this->user()->role_type, ['teacher', 'homeroom_teacher', 'admin', 'school_admin']);
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
            'student_id' => ['required', 'exists:users,id'],
            'attendance_date' => ['required', 'date', 'before_or_equal:today'],
            'status' => ['required', 'in:sick,permit,absent,excused'], // NO 'present' or 'late'
            'notes' => ['nullable', 'string', 'max:500'],
            'attachment_url' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'status.in' => 'Status manual hanya boleh: sakit, izin, alpa, atau excused. Untuk hadir gunakan scan QR.',
            'schedule_id.exists' => 'Jadwal tidak ditemukan',
            'student_id.exists' => 'Siswa tidak ditemukan',
            'attendance_date.before_or_equal' => 'Tanggal absensi tidak boleh di masa depan',
        ];
    }
}
