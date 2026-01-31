<?php

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

class MonthlySummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()->role_type, ['teacher', 'homeroom_teacher', 'admin', 'school_admin', 'super_admin']);
    }

    public function rules(): array
    {
        return [
            'class_id' => 'required|exists:classes,id',
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:'.(date('Y') + 1),
        ];
    }

    public function messages(): array
    {
        return [
            'class_id.required' => 'Kelas wajib dipilih.',
            'class_id.exists' => 'Kelas tidak ditemukan.',
            'month.required' => 'Bulan wajib dipilih.',
            'month.integer' => 'Bulan harus berupa angka.',
            'month.min' => 'Bulan minimal 1 (Januari).',
            'month.max' => 'Bulan maksimal 12 (Desember).',
            'year.required' => 'Tahun wajib diisi.',
            'year.integer' => 'Tahun harus berupa angka.',
            'year.min' => 'Tahun minimal 2020.',
            'year.max' => 'Tahun maksimal '.(date('Y') + 1).'.',
        ];
    }
}
