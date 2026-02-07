<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreManualAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role_type === 'teacher';
    }

    public function rules(): array
    {
        return [
            'student_id' => 'required|exists:users,id',
            'status' => 'required|in:present,late,absent,sick,permit',
            'reason' => 'required|string|max:255',
        ];
    }
}
