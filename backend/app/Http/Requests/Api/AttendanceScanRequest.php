<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * AttendanceScanRequest - Refactored for Server-Side Logic
 *
 * PRINCIPLES:
 * 1. Accept RAW DATA only from client
 * 2. NO status field (server determines)
 * 3. Validate data types and formats
 * 4. Server performs ALL business logic
 *
 * VALIDATION:
 * - QR token format
 * - GPS coordinates validity
 * - Device fingerprint presence
 * - Request ID (idempotency)
 *
 * @version 2.0.0 - Server-side logic only
 */
class AttendanceScanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only students can scan for attendance
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
            // QR Token (required, string)
            'qr_token' => 'required|string|min:10',
            
            // Location Data (RAW - server validates radius & speed)
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
            'altitude' => 'nullable|numeric',
            'speed' => 'nullable|numeric|min:0',
            'heading' => 'nullable|numeric|between:0,360',
            
            // Security Metadata
            'is_mocked' => 'nullable|boolean',
            'device_fingerprint' => 'required|string|max:255',
            'security_context' => 'nullable|array',
            'security_context.is_secure' => 'nullable|boolean',
            'security_context.risk_level' => 'nullable|string|in:low,medium,high,critical,unknown',
            'security_context.violation_count' => 'nullable|integer|min:0',
            'security_context.violations' => 'nullable|array',
            'security_context.violations.*' => 'string',
            
            // Idempotency & Retry Support
            'request_id' => 'required|uuid',
            
            // Timestamps (for server validation, NOT for record)
            'client_timestamp' => 'nullable|integer',
            'scanned_at' => 'nullable|date_format:Y-m-d\TH:i:s.u\Z,Y-m-d\TH:i:s\Z',
            
            // REMOVED: status field (server determines)
            // REMOVED: computed fields
            // REMOVED: client-side validations
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'qr_token.required' => 'QR token diperlukan.',
            'qr_token.min' => 'QR token tidak valid.',
            
            'latitude.required' => 'Lokasi GPS diperlukan.',
            'latitude.between' => 'Latitude tidak valid.',
            'longitude.required' => 'Lokasi GPS diperlukan.',
            'longitude.between' => 'Longitude tidak valid.',
            
            'device_fingerprint.required' => 'Device fingerprint diperlukan.',
            
            'request_id.required' => 'Request ID diperlukan.',
            'request_id.uuid' => 'Request ID harus berformat UUID.',
            
            'security_context.risk_level.in' => 'Risk level tidak valid.',
        ];
    }

    /**
     * Get validated data with defaults
     *
     * Provides safe defaults for optional fields
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
            'security_context' => [
                'is_secure' => true,
                'risk_level' => 'unknown',
                'violation_count' => 0,
                'violations' => [],
            ],
            'client_timestamp' => null,
            'scanned_at' => null,
        ], $validated);
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validasi gagal. Periksa data yang dikirim.',
            'errors' => $validator->errors(),
        ], 422));
    }

    /**
     * Prepare the data for validation.
     *
     * Normalize data before validation
     */
    protected function prepareForValidation()
    {
        // Normalize boolean values
        if ($this->has('is_mocked')) {
            $this->merge([
                'is_mocked' => filter_var($this->is_mocked, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            ]);
        }

        // Ensure security_context is array
        if ($this->has('security_context') && is_string($this->security_context)) {
            $this->merge([
                'security_context' => json_decode($this->security_context, true) ?? [],
            ]);
        }
    }
}
