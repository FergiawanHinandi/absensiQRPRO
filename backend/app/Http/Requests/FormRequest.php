<?php

namespace App\Http\Requests;

use App\Http\Responses\ResponseFormatter;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest as BaseFormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * FormRequest Base Class
 *
 * Extends Laravel's FormRequest to use ResponseFormatter for validation errors.
 *
 * USAGE:
 * - Extend this class instead of Illuminate\Foundation\Http\FormRequest
 * - Validation errors will automatically use ResponseFormatter
 *
 * EXAMPLE:
 * class StoreUserRequest extends FormRequest
 * {
 *     public function rules()
 *     {
 *         return ['email' => 'required|email'];
 *     }
 * }
 *
 * @version 1.0.0
 */
abstract class FormRequest extends BaseFormRequest
{
    /**
     * Handle a failed validation attempt.
     *
     * @param Validator $validator
     * @return void
     *
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            ResponseFormatter::validationError(
                $validator->errors()->toArray(),
                'Validation failed'
            )
        );
    }

    /**
     * Handle a failed authorization attempt.
     *
     * @return void
     *
     * @throws HttpResponseException
     */
    protected function failedAuthorization()
    {
        throw new HttpResponseException(
            ResponseFormatter::forbidden('You are not authorized to perform this action')
        );
    }
}
