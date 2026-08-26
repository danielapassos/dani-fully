<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts\Instagram;

use App\Exceptions\TokenRefreshException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;

class InstagramTokenRefresher
{
    public function __construct(private readonly HttpFactory $http) {}

    /** @return array{token: string, expiresAt: CarbonImmutable} */
    public function refresh(string $longToken): array
    {
        $response = $this->http->timeout(10)->connectTimeout(5)->acceptJson()
            ->get('https://graph.instagram.com/refresh_access_token', [
                'grant_type' => 'ig_refresh_token',
                'access_token' => $longToken,
            ]);

        if ($response->failed()) {
            throw new TokenRefreshException('Instagram token refresh failed: '.$this->errorDetail($response));
        }

        $token = (string) $response->json('access_token');
        $expiresIn = (int) $response->json('expires_in');

        if ($token === '' || $expiresIn <= 0) {
            throw new TokenRefreshException('Instagram token refresh returned an invalid token payload.');
        }

        return [
            'token' => $token,
            'expiresAt' => Date::now()->addSeconds($expiresIn)->toImmutable(),
        ];
    }

    private function errorDetail(Response $response): string
    {
        $error = $response->json('error');

        if (is_array($error)) {
            $error = $error['message'] ?? null;
        }

        return (string) ($response->json('error_description') ?? $error ?? $response->body());
    }
}
