<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts\TikTok;

use Illuminate\Validation\Rule;

class TikTokPostOptions
{
    public const array PRIVACY_LEVELS = ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'];

    public const array BOOLEAN_FIELDS = ['disable_comment', 'disable_duet', 'disable_stitch', 'commercial_content', 'brand_organic_toggle', 'brand_content_toggle', 'is_aigc', 'music_usage_confirmed', 'branded_content_policy_confirmed'];

    /** @return array<string, mixed> */
    public static function draftRules(): array
    {
        $prefix = 'targets.*.content_override.tiktok';
        $fields = ['privacy_level', ...self::BOOLEAN_FIELDS, 'video_cover_timestamp_ms'];

        return [
            $prefix => ['nullable', 'array:'.implode(',', $fields)],
            $prefix.'.privacy_level' => ['nullable', Rule::in(self::PRIVACY_LEVELS)],
            ...array_fill_keys(array_map(static fn (string $field): string => $prefix.'.'.$field, self::BOOLEAN_FIELDS), ['boolean:strict']),
            $prefix.'.video_cover_timestamp_ms' => ['integer', 'min:0'],
        ];
    }

    /** @param array<string, mixed> $options
     * @param  array<string, mixed>|null  $creator
     * @return list<string>
     */
    public function issues(array $options, ?array $creator = null, ?int $durationSeconds = null): array
    {
        $issues = [];
        $privacy = $options['privacy_level'] ?? null;
        if (! in_array($privacy, self::PRIVACY_LEVELS, true)
            || ($creator !== null && ! in_array($privacy, $creator['privacy_level_options'] ?? [], true))) {
            $issues[] = 'tiktok_privacy_required';
        }
        foreach (self::BOOLEAN_FIELDS as $field) {
            if (! is_bool($options[$field] ?? null)) {
                $issues[] = 'tiktok_options_required';
            }
        }
        if (($options['music_usage_confirmed'] ?? null) !== true) {
            $issues[] = 'tiktok_music_consent_required';
        }
        $commercial = ($options['commercial_content'] ?? false) === true;
        $ownBrand = ($options['brand_organic_toggle'] ?? false) === true;
        $branded = ($options['brand_content_toggle'] ?? false) === true;
        if (($commercial && ! $ownBrand && ! $branded) || (! $commercial && ($ownBrand || $branded))) {
            $issues[] = 'tiktok_commercial_disclosure_required';
        }
        if ($branded && $privacy === 'SELF_ONLY') {
            $issues[] = 'tiktok_branded_content_private';
        }
        if ($branded && ($options['branded_content_policy_confirmed'] ?? null) !== true) {
            $issues[] = 'tiktok_branded_consent_required';
        }
        if ($durationSeconds === null || $durationSeconds < 1) {
            $issues[] = 'tiktok_duration_unknown';
        } elseif ($creator !== null && $durationSeconds > ($creator['max_video_post_duration_sec'] ?? 0)) {
            $issues[] = 'tiktok_duration_exceeded';
        }
        if (array_key_exists('video_cover_timestamp_ms', $options)
            && (! is_int($options['video_cover_timestamp_ms']) || $options['video_cover_timestamp_ms'] < 0
                || $durationSeconds === null || $options['video_cover_timestamp_ms'] >= $durationSeconds * 1000)) {
            $issues[] = 'tiktok_cover_invalid';
        }
        foreach (['comment', 'duet', 'stitch'] as $interaction) {
            if ($creator !== null && ($creator[$interaction.'_disabled'] ?? true) === true
                && ($options['disable_'.$interaction] ?? null) !== true) {
                $issues[] = 'tiktok_interaction_unavailable';
            }
        }

        return array_values(array_unique($issues));
    }

    public function describe(string $issue): string
    {
        return match ($issue) {
            'tiktok_privacy_required' => 'Choose a privacy setting currently available for this TikTok account.',
            'tiktok_options_required' => 'Review the TikTok publishing controls before posting.',
            'tiktok_music_consent_required' => 'Agree to TikTok’s Music Usage Confirmation before posting.',
            'tiktok_commercial_disclosure_required' => 'Indicate whether commercial content promotes your brand, a third party, or both.',
            'tiktok_branded_content_private' => 'Branded content visibility cannot be set to private.',
            'tiktok_branded_consent_required' => 'Agree to TikTok’s Branded Content Policy before posting branded content.',
            'tiktok_duration_unknown' => 'The video duration must be known before posting to TikTok.',
            'tiktok_duration_exceeded' => 'The video is longer than this TikTok account currently allows.',
            'tiktok_cover_invalid' => 'Choose a cover time within the video.',
            'tiktok_interaction_unavailable' => 'An interaction is disabled in this TikTok account’s settings. Refresh the publishing controls.',
            default => 'Review the TikTok publishing settings before posting.',
        };
    }
}
