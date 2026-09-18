<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\Platform;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Services\Posts\VideoUploadService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Override;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

#[Description('Begin an original-quality MP4 upload to the bound workspace media library. Send the exact file bytes with HTTP PUT to the returned temporary URL using its headers, then call complete_video_upload. Do not send file bytes through MCP. The signed URL and headers are temporary secrets: never include them in posts or logs. Bytes and resolution are preserved without transcoding; platform publishing limits still apply. This does not create, attach, schedule, or publish a post.')]
#[Name('begin_video_upload')]
class BeginVideoUploadTool extends WorkspaceTool
{
    public function handle(Request $request, VideoUploadService $uploads): Response
    {
        $workspaceId = $this->bindWorkspace($request);
        if ($workspaceId === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }
        if ($denied = $this->authorize($request, 'create', Post::class)) {
            return $denied;
        }
        $validated = $request->validate(VideoUploadService::beginRules());

        try {
            $upload = $uploads->begin($workspaceId, (string) $request->user()->getAuthIdentifier(), $validated);
        } catch (HttpExceptionInterface $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::text(json_encode($upload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, Type> */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'content_type' => $schema->string()->enum(['video/mp4'])->required(),
            'size_bytes' => $schema->integer()->min(12)->max(Platform::maxVideoBytesCeiling())->description('Exact source file size in bytes.')->required(),
            'width' => $schema->integer()->min(1)->max(2147483647)->description('Source video width in pixels; not a resize request.')->required(),
            'height' => $schema->integer()->min(1)->max(2147483647)->description('Source video height in pixels; not a resize request.')->required(),
            'duration_seconds' => $schema->integer()->min(1)->max(2147483647)->description('Video duration in whole seconds, rounded up.')->required(),
            'alt_text' => $schema->string()->max(255)->nullable()->description('Optional accessibility text.'),
            'sha256' => $schema->string()->pattern('^[a-fA-F0-9]{64}$')->nullable()->description('Optional SHA-256 hex digest of the original file for server-side byte-integrity verification.'),
        ];
    }
}
