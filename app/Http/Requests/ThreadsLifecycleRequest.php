<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\ConnectedAccounts\Threads\ThreadsSignedRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ThreadsLifecycleRequest extends FormRequest
{
    /** @var array{remote_account_id: string, issued_at: int} */
    private array $authorizationEvent;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['signed_request' => ['required', 'string', 'max:16384']];
    }

    public function remoteAccountId(): string
    {
        return $this->authorizationEvent['remote_account_id'];
    }

    public function issuedAt(): int
    {
        return $this->authorizationEvent['issued_at'];
    }

    protected function passedValidation(): void
    {
        $this->authorizationEvent = app(ThreadsSignedRequest::class)->verify($this->validated('signed_request'));
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json(['error' => 'Invalid signed request.'], 400));
    }
}
