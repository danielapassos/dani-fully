<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Services\Posts\VideoUploadService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Override;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

#[Description('Verify and finalize an MP4 uploaded with begin_video_upload. Call only after its HTTP PUT succeeds. Returns workspace media metadata and id; repeating completion returns the same media. Use the existing post-update tool with media_ids to attach it to the intended draft. Does not create, schedule, or publish a post. Original bytes and resolution are preserved; existing publishing limits remain in force.')]
#[Name('complete_video_upload')]
class CompleteVideoUploadTool extends WorkspaceTool
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
        $validated = $request->validate(['upload_id' => ['required', 'uuid']]);

        try {
            $media = $uploads->complete($workspaceId, (string) $request->user()->getAuthIdentifier(), $validated['upload_id']);
        } catch (ModelNotFoundException) {
            return Response::error('No video upload with that id exists for this user in this workspace.');
        } catch (HttpExceptionInterface $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::text(json_encode($media, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, Type> */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return ['upload_id' => $schema->string()->format('uuid')->description('Upload id returned by begin_video_upload for this user and workspace.')->required()];
    }
}
