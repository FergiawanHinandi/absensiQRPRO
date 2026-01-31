<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSubscriptionPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role_type === 'super_admin';
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'price' => 'sometimes|numeric|min:0',
            'duration_months' => 'sometimes|integer|min:1|max:60',
            'features' => 'sometimes|array',
            'features.max_students' => 'sometimes|integer|min:1',
            'features.max_teachers' => 'sometimes|integer|min:1',
            'features.max_classes' => 'sometimes|integer|min:1',
            'description' => 'nullable|string',
            'is_popular' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
