<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Jobs\ReconcileTikTokInbox;
use App\Models\PostTarget;
use App\Services\Publishing\TikTokInboxReconciliation;
use Illuminate\Console\Command;

class ReconcileTikTokInboxes extends Command
{
    protected $signature = 'tiktok:reconcile-inbox {--days=30} {--limit=100}';

    protected $description = 'Check existing TikTok inbox uploads for manually published public posts without uploading again.';

    public function handle(TikTokInboxReconciliation $reconciliation): int
    {
        $days = max(1, min(365, (int) $this->option('days')));
        $limit = max(1, min(500, (int) $this->option('limit')));
        $count = 0;
        $accounts = [];
        foreach (PostTarget::query()->where('platform', Platform::TikTok)
            ->whereIn('status', [PostTargetStatus::AwaitingAction, PostTargetStatus::Completed, PostTargetStatus::Publishing, PostTargetStatus::Published])
            ->where('created_at', '>=', now()->subDays($days))
            ->whereHas('account', fn ($query) => $query->whereNull('disabled_at'))
            ->orderBy('updated_at')->orderBy('id')->cursor() as $target) {
            if (($accounts[$target->connected_account_id] ?? 0) < 10 && $reconciliation->due($target)) {
                $accounts[$target->connected_account_id] = ($accounts[$target->connected_account_id] ?? 0) + 1;
                ReconcileTikTokInbox::dispatch($target);
                if (++$count >= $limit) {
                    break;
                }
            }
        }
        $this->info("Queued {$count} existing inbox uploads for status checks.");

        return self::SUCCESS;
    }
}
