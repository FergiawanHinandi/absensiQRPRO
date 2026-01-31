<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceScanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only students can scan QR codes
        return $this->user() && $this->user()->role_type === 'student';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'device_info' => ['nullable', 'array'],
            'device_info.device_id' => ['nullable', 'string', 'max:255'],
            'device_info.os' => ['nullable', 'string', 'max:100'],
            'device_info.model' => ['nullable', 'string', 'max:100'],
            'request_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'token.required' => 'QR code token diperlukan',
            'latitude.required' => 'Lokasi GPS diperlukan',
            'longitude.required' => 'Lokasi GPS diperlukan',
            'latitude.between' => 'Koordinat latitude tidak valid',
            'longitude.between' => 'Koordinat longitude tidak valid',
        ];
    }
}
