<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts\Instagram;

use App\Models\ConnectedAccount;

final class InstagramGraphApi
{
    public static function baseUrl(?ConnectedAccount $account): string
    {
        if ($account?->usesInstagramLogin()) {
            $version = (string) config('services.instagram.graph_version', 'v25.0');
            $version = str_starts_with($version, 'v') ? $version : 'v'.$version;

            return sprintf(
                'https://graph.instagram.com/%s',
                $version,
            );
        }

        return sprintf(
            'https://graph.facebook.com/%s',
            (string) config('services.facebook.graph_version', 'v25.0'),
        );
    }
}
