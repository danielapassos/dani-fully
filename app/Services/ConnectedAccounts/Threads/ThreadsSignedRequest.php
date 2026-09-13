<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts\Threads;

use Illuminate\Http\Exceptions\HttpResponseException;
use JsonException;

class ThreadsSignedRequest
{
    /** @return array{remote_account_id: string, issued_at: int} */
    public function verify(string $signedRequest): array
    {
        $secret = config('services.threads.client_secret');

        if (! is_string($secret) || trim($secret) === '') {
            $this->reject(503, 'Threads callbacks are not configured.');
        }

        $parts = explode('.', $signedRequest);
        if (count($parts) !== 2) {
            $this->reject(400);
        }

        [$encodedSignature, $encodedPayload] = $parts;
        $signature = $this->decode($encodedSignature);
        $payload = $this->decode($encodedPayload);

        if (strlen($signature) !== 32 || ! hash_equals(hash_hmac('sha256', $encodedPayload, $secret, true), $signature)) {
            $this->reject(403);
        }

        try {
            $data = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->reject(400);
        }

        if (! is_array($data)
            || ($data['algorithm'] ?? null) !== 'HMAC-SHA256'
            || ! is_string($data['user_id'] ?? null)
            || ! preg_match('/\A[1-9][0-9]{0,63}\z/', $data['user_id'])
            || ! is_int($data['issued_at'] ?? null)
            || $data['issued_at'] <= 0
            || $data['issued_at'] > now()->getTimestamp() + 300
            || (array_key_exists('expires', $data) && (! is_int($data['expires']) || $data['expires'] < 0))) {
            $this->reject(400);
        }

        if (($data['expires'] ?? 0) !== 0 && $data['expires'] <= now()->getTimestamp()) {
            $this->reject(403);
        }

        return ['remote_account_id' => $data['user_id'], 'issued_at' => $data['issued_at']];
    }

    private function decode(string $encoded): string
    {
        if (! preg_match('/\A[A-Za-z0-9_-]+={0,2}\z/', $encoded)) {
            $this->reject(400);
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($decoded === false) {
            $this->reject(400);
        }

        return $decoded;
    }

    private function reject(int $status, string $message = 'Invalid signed request.'): never
    {
        throw new HttpResponseException(response()->json(['error' => $message], $status));
    }
}
