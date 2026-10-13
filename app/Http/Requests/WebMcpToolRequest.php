<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Mcp\Web\WebMcpResponse;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Override;

class WebMcpToolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web') !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'uuid'],
            'workspace_id' => ['required', 'uuid'],
            'arguments' => $this->isMethod('POST') ? [
                'present',
                'array',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_array($value) && (array_key_exists('workspace_id', $value) || array_key_exists('user_id', $value))) {
                        $fail('Tool arguments cannot override the signed-in user or selected workspace.');
                    }
                },
            ] : ['missing'],
        ];
    }

    #[Override]
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(WebMcpResponse::error(implode(' ', $validator->errors()->all()), 422));
    }
}
