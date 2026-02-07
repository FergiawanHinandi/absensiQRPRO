<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class SchoolManagementRequest extends FormRequest
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
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'type' => 'required|in:SD,SMP,SMA,SMK',
            'status' => 'sometimes|in:active,inactive,suspended',
            'subscription_package_id' => 'required|exists:subscription_packages,id',
            'max_students' => 'required|integer|min:1|max:10000',
            'timezone' => 'required|string|max:50',
        ];

        // For updates, make email unique except for current school
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $schoolId = $this->route('school');
            $rules['email'] = "required|email|max:255|unique:schools,email,{$schoolId}";
        } else {
            $rules['email'] = 'required|email|max:255|unique:schools,email';
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama sekolah wajib diisi.',
            'email.required' => 'Email sekolah wajib diisi.',
            'email.unique' => 'Email sekolah sudah digunakan.',
            'type.required' => 'Jenis sekolah wajib dipilih.',
            'type.in' => 'Jenis sekolah harus salah satu dari: SD, SMP, SMA, SMK.',
            'subscription_package_id.required' => 'Paket berlangganan wajib dipilih.',
            'subscription_package_id.exists' => 'Paket berlangganan tidak valid.',
            'max_students.required' => 'Batas maksimal siswa wajib diisi.',
            'max_students.min' => 'Batas maksimal siswa minimal 1.',
            'max_students.max' => 'Batas maksimal siswa maksimal 10.000.',
        ];
    }
}