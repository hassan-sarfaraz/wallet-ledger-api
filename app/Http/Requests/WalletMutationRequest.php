<?php

namespace App\Http\Requests;

use App\Exceptions\WalletException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use JsonException;

class WalletMutationRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['bail', 'required', 'string', 'regex:/\A[A-Za-z0-9_-]{1,64}\z/'],
            'amount' => ['bail', 'required', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_int($value) || $value < 1 || $value > 1_000_000_000) {
                    $fail('amount must be a JSON integer between 1 and 1000000000.');
                }
            }],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            $data = json_decode($this->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WalletException('invalid_request', 'Request body must be a valid JSON object.', 400);
        }

        if (! $data instanceof \stdClass) {
            throw new WalletException('invalid_request', 'Request body must be a valid JSON object.', 400);
        }

        return (array) $data;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new WalletException('invalid_request', $validator->errors()->first(), 400);
    }
}
