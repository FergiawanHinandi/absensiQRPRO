<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSchoolRequest extends FormRequest
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
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'name' => 'sometimes|string',
            'npsn' => 'nullable|string|unique:schools,npsn,'.$id,
            'school_level' => 'sometimes|in:SD,SMP,SMA,SMK',
            'address' => 'sometimes',
            'email' => 'sometimes|email|unique:schools,email,'.$id,
            'phone' => 'nullable',
            'timezone' => 'nullable|string|timezone',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_meters' => 'nullable|integer|min:0|max:1000',
            'package_type' => 'sometimes|string',
            'max_students' => 'sometimes|integer',
            'max_teachers' => 'sometimes|integer',
            'max_classes' => 'sometimes|integer',
        ];
    }
}
