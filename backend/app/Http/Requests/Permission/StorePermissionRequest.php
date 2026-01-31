<?php

namespace App\Http\Requests\Permission;

use Illuminate\Foundation\Http\FormRequest;

class StorePermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()->role_type, ['student', 'teacher', 'homeroom_teacher', 'admin', 'school_admin']);
    }

    public function rules(): array
    {
        $user = $this->user();
        $isTeacher = in_array($user->role_type, ['teacher', 'homeroom_teacher', 'admin', 'school_admin']);

        $rules = [
            'permission_type' => 'required|in:sick,permit,late,early_leave',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string|max:500',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ];

        // If teacher is creating permission for student
        if ($isTeacher) {
            $rules['student_id'] = 'required|exists:users,id';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'permission_type.required' => 'Jenis izin wajib dipilih.',
            'permission_type.in' => 'Jenis izin tidak valid.',
            'start_date.required' => 'Tanggal mulai wajib diisi.',
            'start_date.after_or_equal' => 'Tanggal mulai tidak boleh sebelum hari ini.',
            'end_date.required' => 'Tanggal selesai wajib diisi.',
            'end_date.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            'reason.required' => 'Alasan wajib diisi.',
            'reason.max' => 'Alasan maksimal 500 karakter.',
            'attachment.file' => 'Lampiran harus berupa file.',
            'attachment.mimes' => 'Lampiran harus berformat JPG, JPEG, PNG, atau PDF.',
            'attachment.max' => 'Ukuran lampiran maksimal 2MB.',
            'student_id.required' => 'Siswa wajib dipilih.',
            'student_id.exists' => 'Siswa tidak ditemukan.',
        ];
    }
}
