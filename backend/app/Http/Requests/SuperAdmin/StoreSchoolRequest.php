<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class StoreSchoolRequest extends FormRequest
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
        return [
            'name' => 'required|string',
            'npsn' => 'required|string|unique:schools,npsn',
            'school_level' => 'required|in:SD,SMP,SMA,SMK',
            'address' => 'required',
            'email' => 'required|email|unique:schools,email',
            'phone' => 'nullable',
            'timezone' => 'nullable|string|timezone',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_meters' => 'nullable|integer|min:0|max:1000',
        ];
    }
}
