<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VideoUploadSession;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VideoUploadSession>
 */
class VideoUploadSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'disk' => 'local',
            'temporary_path' => fn (array $attributes): string => 'tmp/media/'.$attributes['workspace_id'].'/'.Str::uuid().'.mp4',
            'final_path' => fn (array $attributes): string => 'media/'.$attributes['workspace_id'].'/'.Str::uuid().'.mp4',
            'size_bytes' => 1024,
            'width' => 3840,
            'height' => 2160,
            'duration_seconds' => 10,
            'expires_at' => now()->addMinutes(15),
        ];
    }
}
