<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Enums\PostStatus;
use App\Models\PostMedia;
use App\Models\PostTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class InstagramReelCover
{
    /** @return array<string, mixed> */
    public static function draftRules(): array
    {
        return [
            'targets.*.content_override.instagram' => ['nullable', 'array:cover_media_id'],
            'targets.*.content_override.instagram.cover_media_id' => ['nullable', 'uuid'],
        ];
    }

    /** @return Builder<PostMedia> */
    public function available(string $workspaceId): Builder
    {
        return PostMedia::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereNull('direct_message_id')
            ->where('kind', 'image')
            ->whereIn('mime', ['image/jpeg', 'image/png'])
            ->where('size_bytes', '>', 0)
            ->where('size_bytes', '<=', 8 * 1024 * 1024);
    }

    /** @param array<string, mixed>|null $override */
    public function validateChoice(string $workspaceId, ?array $override): void
    {
        $id = $override['instagram']['cover_media_id'] ?? null;
        if ($id !== null && (! is_string($id) || ! $this->available($workspaceId)->whereKey($id)->exists())) {
            throw ValidationException::withMessages([
                'targets' => 'Choose an existing JPEG or PNG image up to 8 MB from this workspace for the Instagram Reel cover.',
            ]);
        }
    }

    public function resolve(PostTarget $target): ?PostMedia
    {
        $id = $target->content_override['instagram']['cover_media_id'] ?? null;
        if (! is_string($id) || $id === '') {
            return null;
        }

        return $this->available($target->post->workspace_id)->whereKey($id)->first();
    }

    /**
     * @param  list<PostMedia>  $media
     * @return list<string>
     */
    public function issues(PostTarget $target, array $media): array
    {
        if ($target->platform !== Platform::Instagram || ($target->content_override['instagram']['cover_media_id'] ?? null) === null) {
            return [];
        }
        if ($target->format === PostFormat::Story || count($media) !== 1 || ! $media[0]->isVideo()) {
            return ['instagram_cover_requires_reel'];
        }

        return $this->resolve($target) === null ? ['instagram_cover_unavailable'] : [];
    }

    public function isReferenced(PostMedia $media): bool
    {
        return PostTarget::withoutGlobalScopes()
            ->where('platform', Platform::Instagram->value)
            ->where('content_override->instagram->cover_media_id', $media->id)
            ->whereHas('post', fn (Builder $query): Builder => $query->withoutGlobalScopes()
                ->where('workspace_id', $media->workspace_id)
                ->where('status', '!=', PostStatus::Deleted->value))
            ->exists();
    }
}
