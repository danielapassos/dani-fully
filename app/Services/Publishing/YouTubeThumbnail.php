<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Support\OAuthGrantedScopes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class YouTubeThumbnail
{
    public const string UNAVAILABLE_MESSAGE = 'Choose an existing JPEG or PNG image up to 8 MB from this workspace for the YouTube cover.';

    public const string RELEASE_SCOPE_MESSAGE = 'Reconnect this YouTube account to grant video management access before publishing with a cover. The upload stays private until YouTube accepts the cover.';

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
        $id = $override['youtube']['thumbnail_media_id'] ?? null;
        if ($id !== null && (! is_string($id) || ! $this->available($workspaceId)->whereKey($id)->exists())) {
            throw ValidationException::withMessages(['targets' => self::UNAVAILABLE_MESSAGE]);
        }
    }

    public function resolve(PostTarget $target): ?PostMedia
    {
        $id = $target->content_override['youtube']['thumbnail_media_id'] ?? null;
        if (! is_string($id) || $id === '') {
            return null;
        }

        return $this->available($target->post->workspace_id)->whereKey($id)->first();
    }

    public function media(PostTarget $target): ?PostMedia
    {
        return $this->resolve($target);
    }

    public function validationError(PostTarget $target): ?string
    {
        if ($target->platform !== Platform::YouTube || ($target->content_override['youtube']['thumbnail_media_id'] ?? null) === null) {
            return null;
        }
        if ($this->resolve($target) === null) {
            return self::UNAVAILABLE_MESSAGE;
        }
        $privacy = app(YouTubePostOptions::class)->resolve($target)['privacy_status'] ?? null;
        if (in_array($privacy, ['public', 'unlisted'], true) && ! $this->canRelease($target->account)) {
            return self::RELEASE_SCOPE_MESSAGE;
        }

        return null;
    }

    public function canRelease(?ConnectedAccount $account): bool
    {
        if ($account === null || $account->platform !== Platform::YouTube) {
            return false;
        }
        $capabilities = $account->capabilities ?? [];
        $verified = ($capabilities['oauth_scopes_verified'] ?? false) === true
            || (! array_key_exists('oauth_scopes_verified', $capabilities) && array_key_exists('oauth_scopes', $capabilities));
        if (! $verified) {
            return false;
        }

        return array_intersect([
            'https://www.googleapis.com/auth/youtube.force-ssl',
            'https://www.googleapis.com/auth/youtube',
        ], OAuthGrantedScopes::normalize($capabilities['oauth_scopes'] ?? null)) !== [];
    }

    /**
     * @param  list<PostMedia>  $media
     * @return list<string>
     */
    public function issues(PostTarget $target, array $media): array
    {
        if ($target->platform !== Platform::YouTube || ($target->content_override['youtube']['thumbnail_media_id'] ?? null) === null) {
            return [];
        }
        if (count($media) !== 1 || ! $media[0]->isVideo()) {
            return ['youtube_thumbnail_requires_video'];
        }

        return match ($this->validationError($target)) {
            self::UNAVAILABLE_MESSAGE => ['youtube_thumbnail_unavailable'],
            self::RELEASE_SCOPE_MESSAGE => ['youtube_thumbnail_release_scope_required'],
            default => [],
        };
    }

    public function isReferenced(PostMedia $media): bool
    {
        $targets = PostTarget::withoutGlobalScopes()
            ->where('platform', Platform::YouTube->value)
            ->whereHas('post', fn (Builder $query): Builder => $query->withoutGlobalScopes()
                ->where('workspace_id', $media->workspace_id)
                ->where('status', '!=', PostStatus::Deleted->value));
        if ((clone $targets)->where('content_override->youtube->thumbnail_media_id', $media->id)->exists()) {
            return true;
        }

        foreach ($targets->whereNotNull('media_upload_state')->select(['id', 'media_upload_state'])->cursor() as $target) {
            foreach ($target->media_upload_state ?? [] as $state) {
                if (is_array($state) && ($state['metadata']['thumbnail']['media_id'] ?? null) === $media->id) {
                    return true;
                }
            }
        }

        return false;
    }
}
