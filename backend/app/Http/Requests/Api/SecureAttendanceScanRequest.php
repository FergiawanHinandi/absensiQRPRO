<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SecureAttendanceScanRequest
 *
 * Validates secure attendance scan requests with cryptographic payload.
 *
 * RESPONSIBILITIES:
 * - Validate QR payload structure
 * - Validate GPS coordinates
 * - Authorize teacher role
 */
class SecureAttendanceScanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (!$user) {
            return false;
        }

        return in_array($user->role_type, ['teacher', 'homeroom_teacher']);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'qr_payload' => ['required', 'array'],
            'qr_payload.student_id' => ['required', 'integer'],
            'qr_payload.nisn' => ['required', 'string'],
            'qr_payload.generated_at' => ['required', 'integer'],
            'qr_payload.signature' => ['required', 'string'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
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
            'qr_payload.required' => 'Payload QR diperlukan.',
            'qr_payload.array' => 'Payload QR harus berupa object.',
            'qr_payload.student_id.required' => 'Student ID diperlukan dalam payload.',
            'qr_payload.nisn.required' => 'NISN diperlukan dalam payload.',
            'qr_payload.generated_at.required' => 'Timestamp diperlukan dalam payload.',
            'qr_payload.signature.required' => 'Signature diperlukan dalam payload.',
            'lat.between' => 'Koordinat latitude tidak valid.',
            'lng.between' => 'Koordinat longitude tidak valid.',
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
