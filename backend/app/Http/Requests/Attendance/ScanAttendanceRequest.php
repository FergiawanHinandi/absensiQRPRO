<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Scan Attendance Request
 * 
 * Validates QR code scan data from mobile app
 * Client sends RAW sensor data only - server determines business logic
 */
class ScanAttendanceRequest extends FormRequest
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
     */
    public function rules(): array
    {
        return [
            // QR Token (required)
            'qr_token' => 'required|string',

            // Location Data (required)
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
            'altitude' => 'nullable|numeric',
            'speed' => 'nullable|numeric|min:0',
            'heading' => 'nullable|numeric|between:0,360',

            // Security Metadata
            'is_mocked' => 'nullable|boolean',
            'device_fingerprint' => 'required|string',
            'request_id' => 'required|string|uuid',

            // Security Context (optional)
            'security_context' => 'nullable|array',
            'security_context.is_secure' => 'nullable|boolean',
            'security_context.risk_level' => 'nullable|string|in:low,medium,high,critical,unknown',
            'security_context.violation_count' => 'nullable|integer|min:0',
            'security_context.violations' => 'nullable|array',

            // Timestamps (for server validation)
            'client_timestamp' => 'nullable|integer',
            'scanned_at' => 'nullable|date_format:Y-m-d\TH:i:s.u\Z',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'qr_token.required' => 'QR token tidak boleh kosong.',
            'latitude.required' => 'Lokasi latitude diperlukan.',
            'longitude.required' => 'Lokasi longitude diperlukan.',
            'device_fingerprint.required' => 'Device fingerprint diperlukan.',
            'request_id.required' => 'Request ID diperlukan.',
            'request_id.uuid' => 'Request ID harus berformat UUID.',
        ];
    }

    /**
     * Get validated data with defaults
     */
    public function validatedWithDefaults(): array
    {
        $validated = $this->validated();

        return array_merge([
            'accuracy' => null,
            'altitude' => null,
            'speed' => null,
            'heading' => null,
            'is_mocked' => false,
            'security_context' => null,
            'client_timestamp' => null,
            'scanned_at' => null,
        ], $validated);
    }
}
