<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreSubmissionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'form_id' => ['required', 'string', 'min:1', 'max:128'],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:191'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'data' => ['required', 'array'],
        ];
    }

    /**
     * Use simple email validation compatible with test domains if dns is unresolvable locally
     */
    protected function prepareForValidation(): void
    {
        // Ensure data is present as an array if given in request body
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Invalid submission data',
            'errors' => $validator->errors(),
        ], 400));
    }
}
