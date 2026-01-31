<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class TeacherAttendanceScanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // User must be authenticated and be a teacher
        $user = $this->user();
        return $user && in_array($user->role_type, ['teacher', 'homeroom_teacher', 'admin']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'qr_token' => ['required', 'string'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'device_id' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'device_model' => ['nullable', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'in:android,ios,web'],
            'is_mock_location' => ['nullable', 'boolean'],
            'request_id' => ['nullable', 'string', 'uuid'],
        ];
    }

    /**
     * Custom validation messages in Indonesian.
     */
    public function messages(): array
    {
        return [
            'qr_token.required' => 'Token QR wajib diisi.',
            'lat.required' => 'Latitude wajib diisi.',
            'lat.numeric' => 'Latitude harus berupa angka.',
            'lat.between' => 'Latitude harus berada di antara -90 dan 90.',
            'lng.required' => 'Longitude wajib diisi.',
            'lng.numeric' => 'Longitude harus berupa angka.',
            'lng.between' => 'Longitude harus berada di antara -180 dan 180.',
            'accuracy.numeric' => 'Akurasi GPS harus berupa angka.',
            'device_id.required' => 'ID perangkat wajib diisi.',
            'device_id.max' => 'ID perangkat maksimal 255 karakter.',
            'platform.in' => 'Platform harus salah satu dari: android, ios, web.',
            'is_mock_location.boolean' => 'is_mock_location harus berupa boolean.',
            'request_id.uuid' => 'request_id harus berupa UUID yang valid.',
        ];
    }

    /**
     * Prepare data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Convert string boolean to actual boolean for is_mock_location
        if ($this->has('is_mock_location')) {
            $isMock = $this->is_mock_location;
            if (is_string($isMock)) {
                $this->merge([
                    'is_mock_location' => filter_var($isMock, FILTER_VALIDATE_BOOLEAN),
                ]);
            }
        }
    }
}
