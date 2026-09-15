<?php

declare(strict_types=1);

namespace App\Support;

final class OAuthGrantedScopes
{
    /** @return list<string> */
    public static function normalize(mixed $scopes): array
    {
        if (! is_array($scopes) && ! is_string($scopes)) {
            return [];
        }

        $normalized = [];
        foreach ((array) $scopes as $scope) {
            if (! is_string($scope)) {
                continue;
            }

            foreach (preg_split('/[\s,]+/', trim($scope), flags: PREG_SPLIT_NO_EMPTY) ?: [] as $value) {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Socialite represents an omitted token-response scope as ['']. An empty
     * normalized list therefore cannot prove the provider denied every scope.
     *
     * @return array{oauth_scopes: list<string>, oauth_scopes_verified: bool}
     */
    public static function capabilities(mixed $approvedScopes): array
    {
        $scopes = self::normalize($approvedScopes);

        return ['oauth_scopes' => $scopes, 'oauth_scopes_verified' => $scopes !== []];
    }
}
