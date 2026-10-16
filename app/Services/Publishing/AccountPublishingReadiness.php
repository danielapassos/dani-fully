<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\Platform;
use App\Models\ConnectedAccount;

final class AccountPublishingReadiness
{
    /** @return array<string, mixed> */
    public function view(ConnectedAccount $account): array
    {
        $provider = $account->platform === Platform::TikTok
            ? (string) config('services.tiktok.publishing_provider', 'native')
            : 'native';
        $inbox = app(TikTokInboxHandoff::class)->enabledFor($account);
        $separateCredentials = app(TikTokPublishingRoute::class)->usesSeparateCredentials($account);
        $permissions = $separateCredentials ? 'separate_credentials' : $account->publishingPermissionStatus();

        return [
            'publishing_readiness' => [
                'basis' => 'stored_configuration_and_credentials',
                'provider_verified' => false,
                'provider_checked_at' => null,
                'permission_status' => $permissions,
                'mode' => $inbox ? 'inbox' : ($provider === 'native' ? 'direct' : $provider),
                'message' => $account->canPublish()
                    ? ($permissions === 'unknown'
                        ? 'The saved connection permits submission, but its publishing permissions are unverified. Current provider eligibility has not been checked.'
                        : 'Saved connection checks passed. Current account eligibility and provider availability have not been verified by this check.')
                    : 'Saved connection checks found a publishing blocker. Resolve the account or installation issue shown here.',
                'browser_session_verified' => false,
            ],
            'music_capabilities' => [
                'native_catalog_search' => false,
                'native_track_attachment' => false,
                'existing_file_audio' => true,
                'native_finishing_required_for_music' => in_array($account->platform, [Platform::TikTok, Platform::Instagram, Platform::YouTube], true),
                'message' => match ($account->platform) {
                    Platform::TikTok => 'Choose and preview licensed music in TikTok. Saved music plans do not attach a track; availability depends on this account and region.',
                    Platform::Instagram => $account->usesInstagramLogin()
                        ? 'Shoutrrr does not attach native music, and this Instagram Login connection does not support the Instagram Audio API. Choose and preview music in the Instagram mobile app when available.'
                        : 'Shoutrrr does not implement Instagram Audio API music selection. Choose and preview music in the Instagram mobile app when available; the web publishing flow does not provide music selection in this workflow.',
                    Platform::YouTube => 'Shoutrrr uploads existing file audio. Native music requires eligible YouTube tools; the web upload flow does not provide music selection in this workflow.',
                    default => 'Shoutrrr uploads existing file audio and does not search or attach platform music tracks.',
                },
                'license_note' => 'Music licensed within one platform is not licensed for reuse on other destinations.',
            ],
        ];
    }
}
