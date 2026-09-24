<?php

declare(strict_types=1);

namespace App\Http\Requests\Post;

use App\Models\Post;
use App\Services\Posts\VideoUploadService;
use Illuminate\Foundation\Http\FormRequest;

class BeginVideoUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Post::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return VideoUploadService::beginRules();
    }
}
