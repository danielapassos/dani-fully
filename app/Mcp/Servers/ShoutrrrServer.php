<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Methods\CallToolWithScopeAuthorization;
use App\Mcp\Tools\AddPostMediaTool;
use App\Mcp\Tools\BeginVideoUploadTool;
use App\Mcp\Tools\CompleteVideoUploadTool;
use App\Mcp\Tools\CreateAccountSetTool;
use App\Mcp\Tools\CreatePostTool;
use App\Mcp\Tools\CreateShareLinkTool;
use App\Mcp\Tools\DeleteAccountSetTool;
use App\Mcp\Tools\DeletePostTool;
use App\Mcp\Tools\DeleteShareTool;
use App\Mcp\Tools\GetCalendarTool;
use App\Mcp\Tools\GetPostingScheduleTool;
use App\Mcp\Tools\GetPostTool;
use App\Mcp\Tools\ListAccountAnalyticsTool;
use App\Mcp\Tools\ListAccountSetsTool;
use App\Mcp\Tools\ListConnectedAccountsTool;
use App\Mcp\Tools\ListPostAnalyticsTool;
use App\Mcp\Tools\ListPostsTool;
use App\Mcp\Tools\ListSharesTool;
use App\Mcp\Tools\ListWorkspacesTool;
use App\Mcp\Tools\PublishPostTool;
use App\Mcp\Tools\QueuePostTool;
use App\Mcp\Tools\RefreshTikTokInboxTool;
use App\Mcp\Tools\RemovePostMediaTool;
use App\Mcp\Tools\RetryPostTargetTool;
use App\Mcp\Tools\SchedulePostTool;
use App\Mcp\Tools\UpdateAccountSetTool;
use App\Mcp\Tools\UpdatePostTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;
use Override;

#[Name('Shoutrrr')]
#[Version('1.0.0')]
#[Instructions('Read and manage social posts, schedules, and connected accounts for one workspace. Read stored analytics with list_account_analytics and list_post_analytics; use captured_at, freshness, and metrics_status to distinguish measured zeros from missing or stale data. Use refresh_tiktok_inbox to check an existing TikTok inbox upload for verified public completion without uploading again. The workspace is fixed at connection time. Use list_workspaces to see which workspace this connection operates on; reconnect to switch. Write tools let you create and edit drafts, schedule, manage media and account sets, and share links. For original MP4 files, use begin_video_upload, stream the original bytes with HTTP PUT to its temporary signed URL, then complete_video_upload and attach the returned media ID to a draft; the browser composer is not required. Keep signed URLs and headers private. Uploading does not publish. Irreversible outward-facing actions (publish_post_now, retry_post_target, delete_post) require explicit human confirmation — call them with confirm=true only after the human approves.')]
class ShoutrrrServer extends Server
{
    // Keep the bounded inventory together for clients that discover only page one.
    // Explicit pagination remains available, and tool permissions are unchanged.
    public int $defaultPaginationLength = 50;

    #[Override]
    protected function boot(): void
    {
        $this->addMethod('tools/call', CallToolWithScopeAuthorization::class);
    }

    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        GetPostTool::class,
        ListWorkspacesTool::class,
        ListPostsTool::class,
        GetCalendarTool::class,
        ListConnectedAccountsTool::class,
        ListAccountSetsTool::class,
        ListAccountAnalyticsTool::class,
        ListPostAnalyticsTool::class,
        GetPostingScheduleTool::class,
        CreatePostTool::class,
        UpdatePostTool::class,
        SchedulePostTool::class,
        QueuePostTool::class,
        AddPostMediaTool::class,
        BeginVideoUploadTool::class,
        CompleteVideoUploadTool::class,
        RemovePostMediaTool::class,
        CreateAccountSetTool::class,
        UpdateAccountSetTool::class,
        DeleteAccountSetTool::class,
        CreateShareLinkTool::class,
        ListSharesTool::class,
        DeleteShareTool::class,
        PublishPostTool::class,
        RetryPostTargetTool::class,
        RefreshTikTokInboxTool::class,
        DeletePostTool::class,
    ];
}
