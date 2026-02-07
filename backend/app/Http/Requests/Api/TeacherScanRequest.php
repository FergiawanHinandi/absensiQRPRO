<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * TeacherScanRequest
 *
 * Validates teacher QR scan requests with comprehensive rules.
 *
 * RESPONSIBILITIES:
 * - Validate QR token format
 * - Validate GPS coordinates
 * - Validate request_id for idempotency
 * - Authorize teacher role
 */
class TeacherScanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Role validation moved from controller to here.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (!$user) {
            return false;
        }

        // Only teachers and homeroom teachers can scan
        return in_array($user->role_type, ['teacher', 'homeroom_teacher']);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'qr_token' => ['required', 'string', 'min:10'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'request_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'qr_token.required' => 'QR token diperlukan untuk scan absensi.',
            'qr_token.min' => 'QR token tidak valid.',
            'lat.between' => 'Koordinat latitude tidak valid.',
            'lng.between' => 'Koordinat longitude tidak valid.',
            'request_id.uuid' => 'Request ID harus berupa UUID yang valid.',
        ];
    }

    /**
     * Handle a failed authorization attempt.
     */
    protected function failedAuthorization()
    {
        throw new \Illuminate\Auth\Access\AuthorizationException(
            'Hanya guru yang dapat melakukan scan absensi siswa.'
        );
    }

    /**
     * Get validated data with defaults
     */
    public function validatedWithDefaults(): array
    {
        $validated = $this->validated();

        return array_merge($validated, [
            'request_id' => $validated['request_id']
                ?? $this->header('X-Request-ID')
                ?? (string) \Illuminate\Support\Str::uuid(),
        ]);
    }
}
