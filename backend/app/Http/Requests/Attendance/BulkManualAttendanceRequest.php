<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class BulkManualAttendanceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        return $user && in_array($user->role_type, [
            'teacher',
            'homeroom_teacher',
            'school_admin',
            'admin',
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'schedule_id' => ['required', 'integer', 'exists:schedules,id'],
            'attendance_date' => ['required', 'date', 'before_or_equal:today'],
            'attendances' => ['required', 'array', 'min:1', 'max:100'],
            'attendances.*.student_id' => ['required', 'integer', 'exists:users,id'],
            'attendances.*.status' => ['required', 'string', 'in:present,late,sick,permit,alpha'],
            'attendances.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Get custom messages for validation errors.
     */
    public function messages(): array
    {
        return [
            'schedule_id.required' => 'Jadwal harus dipilih.',
            'schedule_id.exists' => 'Jadwal tidak ditemukan.',
            'attendance_date.required' => 'Tanggal absensi harus diisi.',
            'attendance_date.before_or_equal' => 'Tanggal absensi tidak boleh di masa depan.',
            'attendances.required' => 'Data kehadiran harus diisi.',
            'attendances.min' => 'Minimal satu data kehadiran harus diisi.',
            'attendances.max' => 'Maksimal 100 data kehadiran per request.',
            'attendances.*.student_id.required' => 'ID siswa harus diisi.',
            'attendances.*.student_id.exists' => 'Siswa tidak ditemukan.',
            'attendances.*.status.required' => 'Status kehadiran harus diisi.',
            'attendances.*.status.in' => 'Status kehadiran tidak valid. Pilih: present, late, sick, permit, atau alpha.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'schedule_id' => 'jadwal',
            'attendance_date' => 'tanggal absensi',
            'attendances' => 'data kehadiran',
            'attendances.*.student_id' => 'ID siswa',
            'attendances.*.status' => 'status kehadiran',
            'attendances.*.notes' => 'catatan',
        ];
    }
}
