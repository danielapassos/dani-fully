<?php

use App\Enums\Platform;

dataset('declared publishing options', [
    'TikTok complete choices' => [Platform::TikTok, 'tiktok', [
        'privacy_level' => 'SELF_ONLY',
        'disable_comment' => true,
        'disable_duet' => true,
        'disable_stitch' => true,
        'commercial_content' => false,
        'brand_organic_toggle' => false,
        'brand_content_toggle' => false,
        'is_aigc' => false,
        'music_usage_confirmed' => true,
        'branded_content_policy_confirmed' => false,
        'video_cover_timestamp_ms' => 1000,
    ]],
    'YouTube complete choices' => [Platform::YouTube, 'youtube', [
        'privacy_status' => 'private',
        'category_id' => '22',
        'format_intent' => 'video',
        'made_for_kids' => false,
        'contains_synthetic_media' => true,
        'has_paid_product_placement' => false,
        'notify_subscribers' => false,
    ]],
    'TikTok incomplete draft choices' => [Platform::TikTok, 'tiktok', ['privacy_level' => 'SELF_ONLY']],
    'YouTube incomplete draft choices' => [Platform::YouTube, 'youtube', ['privacy_status' => 'unlisted']],
    'YouTube independent copy' => [Platform::YouTube, 'youtube', [
        'privacy_status' => 'private', 'category_id' => '22', 'format_intent' => 'video',
        'made_for_kids' => false, 'contains_synthetic_media' => false,
        'has_paid_product_placement' => false, 'notify_subscribers' => false,
        'title' => 'Separate title', 'description' => "Separate description\nwith its own second line.",
    ]],
]);

dataset('invalid publishing options', [
    'TikTok string consent' => [Platform::TikTok, 'tiktok', ['music_usage_confirmed' => 'true']],
    'YouTube string declaration' => [Platform::YouTube, 'youtube', ['made_for_kids' => 'false']],
    'TikTok unsupported privacy' => [Platform::TikTok, 'tiktok', ['privacy_level' => 'public']],
    'YouTube unsupported privacy' => [Platform::YouTube, 'youtube', ['privacy_status' => 'followers']],
    'TikTok arbitrary mode override' => [Platform::TikTok, 'tiktok', ['publish_mode' => 'direct']],
    'YouTube arbitrary scope override' => [Platform::YouTube, 'youtube', ['granted_scope' => 'youtube.upload']],
    'YouTube blank title' => [Platform::YouTube, 'youtube', ['title' => '']],
    'YouTube invalid description' => [Platform::YouTube, 'youtube', ['description' => '<not allowed>']],
]);

dataset('TikTok publishing settings edits', function (): array {
    $stored = [
        'privacy_level' => 'PUBLIC_TO_EVERYONE',
        'disable_comment' => false,
        'disable_duet' => true,
        'disable_stitch' => true,
        'commercial_content' => false,
        'brand_organic_toggle' => false,
        'brand_content_toggle' => false,
        'is_aigc' => false,
        'music_usage_confirmed' => true,
        'branded_content_policy_confirmed' => false,
    ];

    return [
        'omitted settings retain the previous choices' => [$stored, ['segments' => ['Edited'], 'media_ids' => []], $stored],
        'null settings retain the previous choices' => [$stored, ['segments' => ['Edited'], 'tiktok' => null], $stored],
        'empty settings explicitly clear the choices' => [$stored, ['segments' => ['Edited'], 'tiktok' => []], []],
        'partial settings explicitly replace the choices' => [$stored, ['segments' => ['Edited'], 'tiktok' => ['privacy_level' => 'SELF_ONLY']], ['privacy_level' => 'SELF_ONLY']],
    ];
});
