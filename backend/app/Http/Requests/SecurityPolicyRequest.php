<?php

namespace App\Http\Requests;

use App\Services\SecurityPolicyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SecurityPolicyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        // Must be authenticated
        if (!$user) {
            return false;
        }

        // Super admin can do anything
        if ($user->isSuperAdmin()) {
            return true;
        }

        // School admin can only manage school-scoped policies for their own school
        if ($user->isSchoolAdmin()) {
            $scopeType = $this->input('scope_type', 'school');
            $scopeId = $this->input('scope_id');

            // Cannot manage global policies
            if ($scopeType === 'global') {
                return false;
            }

            // Can only manage their own school
            return $scopeId === $user->school_id;
        }

        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $rules = [];

        // Rules for store (POST)
        if ($this->isMethod('POST')) {
            $rules = [
                'scope_type' => ['required', Rule::in(['global', 'school'])],
                'scope_id' => [
                    'nullable',
                    'integer',
                    'exists:schools,id',
                    Rule::requiredIf($this->input('scope_type') === 'school'),
                ],
                'key' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::in(array_keys(SecurityPolicyService::DEFAULTS)),
                    // Unique constraint for scope_type + scope_id + key
                    Rule::unique('security_policies')
                        ->where('scope_type', $this->input('scope_type'))
                        ->where('scope_id', $this->input('scope_id')),
                ],
                'value' => ['required', $this->getValueValidationRule()],
                'description' => ['nullable', 'string', 'max:1000'],
            ];
        }

        // Rules for update (PUT/PATCH)
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules = [
                'value' => ['required', $this->getValueValidationRule()],
                'description' => ['nullable', 'string', 'max:1000'],
            ];
        }

        // Rules for bulk update
        if ($this->routeIs('*.bulk*')) {
            $rules = [
                'policies' => ['required', 'array', 'min:1', 'max:50'],
                'policies.*.key' => [
                    'required',
                    'string',
                    Rule::in(array_keys(SecurityPolicyService::DEFAULTS)),
                ],
                'policies.*.value' => ['required'],
                'policies.*.scope_type' => ['required', Rule::in(['global', 'school'])],
                'policies.*.scope_id' => ['nullable', 'integer', 'exists:schools,id'],
            ];
        }

        return $rules;
    }

    /**
     * Get custom validation rule for value based on policy key.
     */
    protected function getValueValidationRule(): \Closure
    {
        return function ($attribute, $value, $fail) {
            $key = $this->input('key') ?? $this->route('id');

            // If we're updating, get the key from the existing policy
            if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
                $policyId = $this->route('id');
                $policy = \DB::table('security_policies')->where('id', $policyId)->first();
                if ($policy) {
                    $key = $policy->key;
                }
            }

            if (!$key) {
                return;
            }

            // Numeric policies with min/max constraints
            $numericPolicies = [
                'attendance.geofence_radius_meters' => ['min' => 10, 'max' => 5000],
                'attendance.teacher_geofence_radius_meters' => ['min' => 10, 'max' => 5000],
                'attendance.max_scan_per_minute' => ['min' => 1, 'max' => 1000],
                'attendance.max_failed_scans_per_2min' => ['min' => 1, 'max' => 100],
                'attendance.qr_expiry_minutes' => ['min' => 1, 'max' => 60],
                'attendance.schedule_tolerance_before_minutes' => ['min' => 0, 'max' => 60],
                'attendance.schedule_tolerance_after_minutes' => ['min' => 0, 'max' => 60],
                'attendance.late_threshold_minutes' => ['min' => 1, 'max' => 120],
                'behavior.anomaly_score_suspicious' => ['min' => 1, 'max' => 10],
                'behavior.anomaly_score_high' => ['min' => 1, 'max' => 20],
                'behavior.anomaly_score_critical' => ['min' => 1, 'max' => 30],
                'behavior.max_anomalies_before_flag' => ['min' => 1, 'max' => 50],
                'behavior.anomaly_window_hours' => ['min' => 1, 'max' => 168],
                'security.admin_session_max_ip_change' => ['min' => 0, 'max' => 10],
                'security.max_devices_per_teacher' => ['min' => 1, 'max' => 10],
                'security.session_timeout_minutes' => ['min' => 5, 'max' => 1440],
                'rate_limit.login_attempts' => ['min' => 1, 'max' => 20],
                'rate_limit.login_decay_minutes' => ['min' => 1, 'max' => 60],
                'rate_limit.api_per_minute' => ['min' => 10, 'max' => 1000],
                'rate_limit.scan_per_minute' => ['min' => 1, 'max' => 100],
                'rate_limit.export_per_hour' => ['min' => 1, 'max' => 50],
                'qr.max_age_hours' => ['min' => 1, 'max' => 168],
                'qr.student_card_max_age_days' => ['min' => 1, 'max' => 730],
                'qr.nonce_ttl_seconds' => ['min' => 60, 'max' => 86400],
            ];

            // Boolean policies
            $booleanPolicies = [
                'security.require_device_approval',
                'security.enable_geofence_check',
                'security.enable_impossible_travel_check',
            ];

            if (isset($numericPolicies[$key])) {
                $constraints = $numericPolicies[$key];

                if (!is_numeric($value)) {
                    $fail("The {$attribute} must be a number for policy '{$key}'.");
                    return;
                }

                $numValue = (int) $value;

                if ($numValue < $constraints['min'] || $numValue > $constraints['max']) {
                    $fail("The {$attribute} must be between {$constraints['min']} and {$constraints['max']} for policy '{$key}'.");
                }
            }

            if (in_array($key, $booleanPolicies)) {
                if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                    $fail("The {$attribute} must be a boolean value for policy '{$key}'.");
                }
            }
        };
    }

    /**
     * Get custom error messages.
     */
    public function messages(): array
    {
        return [
            'scope_type.required' => 'The scope type is required (global or school).',
            'scope_type.in' => 'The scope type must be either "global" or "school".',
            'scope_id.required_if' => 'The school ID is required when scope type is "school".',
            'scope_id.exists' => 'The selected school does not exist.',
            'key.required' => 'The policy key is required.',
            'key.in' => 'The policy key is not valid. Please use a supported policy key.',
            'key.unique' => 'A policy with this key already exists for the specified scope.',
            'value.required' => 'The policy value is required.',
            'description.max' => 'The description may not be greater than 1000 characters.',
            'policies.required' => 'At least one policy is required for bulk update.',
            'policies.max' => 'Cannot update more than 50 policies at once.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'scope_type' => 'scope type',
            'scope_id' => 'school ID',
            'key' => 'policy key',
            'value' => 'policy value',
            'description' => 'description',
            'policies.*.key' => 'policy key',
            'policies.*.value' => 'policy value',
            'policies.*.scope_type' => 'scope type',
            'policies.*.scope_id' => 'school ID',
        ];
    }

    /**
     * Handle a failed authorization attempt.
     */
    protected function failedAuthorization()
    {
        $user = $this->user();

        if (!$user) {
            throw new \Illuminate\Auth\AuthenticationException('Unauthenticated.');
        }

        $scopeType = $this->input('scope_type', 'school');

        if ($scopeType === 'global' && !$user->isSuperAdmin()) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                'Only super administrators can manage global security policies.'
            );
        }

        throw new \Illuminate\Auth\Access\AuthorizationException(
            'You are not authorized to manage security policies for this school.'
        );
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Convert string booleans to actual booleans
        if ($this->has('value')) {
            $value = $this->input('value');

            if ($value === 'true') {
                $this->merge(['value' => true]);
            } elseif ($value === 'false') {
                $this->merge(['value' => false]);
            }
        }

        // Ensure scope_id is null for global policies
        if ($this->input('scope_type') === 'global') {
            $this->merge(['scope_id' => null]);
        }
    }
}
