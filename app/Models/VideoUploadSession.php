<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use Carbon\CarbonImmutable;
use Database\Factories\VideoUploadSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $user_id
 * @property string $disk
 * @property string $temporary_path
 * @property string $final_path
 * @property int $size_bytes
 * @property int $width
 * @property int $height
 * @property int $duration_seconds
 * @property string|null $alt_text
 * @property string|null $expected_sha256
 * @property string|null $observed_sha256
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $media_id
 */
#[Fillable([
    'workspace_id', 'user_id', 'disk', 'temporary_path', 'final_path',
    'size_bytes', 'width', 'height', 'duration_seconds', 'alt_text',
    'expected_sha256', 'observed_sha256', 'expires_at', 'completed_at', 'media_id',
])]
class VideoUploadSession extends Model
{
    /** @use HasFactory<VideoUploadSessionFactory> */
    use HasFactory, HasUuids, HasWorkspaceScope;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer', 'width' => 'integer', 'height' => 'integer',
            'duration_seconds' => 'integer', 'expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PostMedia, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(PostMedia::class, 'media_id');
    }
}
