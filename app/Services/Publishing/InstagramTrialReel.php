<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Models\PostMedia;
use App\Models\PostTarget;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class InstagramTrialReel
{
    public const array GRADUATION_STRATEGIES = ['MANUAL', 'SS_PERFORMANCE'];

    /** @return array<string, mixed> */
    public static function draftRules(): array
    {
        $prefix = 'targets.*.content_override.instagram.trial_params';

        return [
            $prefix => ['nullable', 'array:graduation_strategy', 'required_array_keys:graduation_strategy'],
            $prefix.'.graduation_strategy' => ['string', Rule::in(self::GRADUATION_STRATEGIES)],
        ];
    }

    /** @return array{graduation_strategy: string}|null */
    public function params(PostTarget $target): ?array
    {
        $params = $target->content_override['instagram']['trial_params'] ?? null;
        if ($params === null) {
            return null;
        }
        if (! $this->valid($params)) {
            throw ValidationException::withMessages(['targets' => $this->describe('instagram_trial_invalid')]);
        }

        return ['graduation_strategy' => $params['graduation_strategy']];
    }

    /** @param list<PostMedia> $media
     * @return list<string>
     */
    public function issues(PostTarget $target, array $media): array
    {
        $params = $target->content_override['instagram']['trial_params'] ?? null;
        if ($params === null) {
            return [];
        }
        if (! $this->valid($params)) {
            return ['instagram_trial_invalid'];
        }
        if ($target->platform !== Platform::Instagram
            || ! in_array($target->format, [PostFormat::Feed, PostFormat::Reels], true)
            || count($media) !== 1 || ! $media[0]->isVideo()) {
            return ['instagram_trial_requires_reel'];
        }

        return [];
    }

    public function describe(string $issue): string
    {
        return match ($issue) {
            'instagram_trial_requires_reel' => 'A Trial Reel requires one Instagram Reel video. Remove trial settings for photos, carousels, Stories, or other platforms.',
            default => 'Choose whether to share this Trial Reel with everyone manually or automatically based on performance.',
        };
    }

    /** @phpstan-assert-if-true array{graduation_strategy: string} $params */
    private function valid(mixed $params): bool
    {
        return is_array($params) && array_keys($params) === ['graduation_strategy']
            && in_array($params['graduation_strategy'], self::GRADUATION_STRATEGIES, true);
    }
}
