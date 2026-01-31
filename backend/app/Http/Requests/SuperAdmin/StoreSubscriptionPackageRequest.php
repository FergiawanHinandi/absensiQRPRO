<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubscriptionPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role_type === 'super_admin';
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'duration_months' => 'required|integer|min:1|max:60',
            'features' => 'required|array',
            'features.max_students' => 'required|integer|min:1',
            'features.max_teachers' => 'required|integer|min:1',
            'features.max_classes' => 'required|integer|min:1',
            'description' => 'nullable|string',
            'is_popular' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama paket wajib diisi.',
            'price.required' => 'Harga wajib diisi.',
            'price.numeric' => 'Harga harus berupa angka.',
            'price.min' => 'Harga tidak boleh negatif.',
            'duration_months.required' => 'Durasi wajib diisi.',
            'duration_months.integer' => 'Durasi harus berupa angka bulat.',
            'duration_months.min' => 'Durasi minimal 1 bulan.',
            'duration_months.max' => 'Durasi maksimal 60 bulan.',
            'features.required' => 'Fitur paket wajib diisi.',
            'features.max_students.required' => 'Maksimal siswa wajib diisi.',
            'features.max_teachers.required' => 'Maksimal guru wajib diisi.',
            'features.max_classes.required' => 'Maksimal kelas wajib diisi.',
        ];
    }
}
