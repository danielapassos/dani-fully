<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConnectedAccountStatus;
use App\Enums\MetricsStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\CaptureAccountMetrics;
use App\Jobs\CapturePostTargetMetrics;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Models\Workspace;
use App\Services\Metrics\StoredAnalytics;
use App\Support\InstanceSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Throwable;

class VerifyMetrics extends Command
{
    protected $signature = 'metrics:verify
        {--workspace= : Exact workspace UUID (required)}
        {--account= : Verify only this connected account UUID}
        {--posts=0 : Also capture this many recent published targets per account (0-20)}
        {--json : Return safe JSON without provider responses or credentials}';

    protected $description = 'Capture and verify saved analytics for at most 100 accounts in one workspace without publishing.';

    public function handle(InstanceSettings $settings, StoredAnalytics $analytics): int
    {
        $workspaceId = $this->option('workspace');
        $accountId = $this->option('account');
        $postLimit = $this->option('posts');
        $validation = validator(['workspace' => $workspaceId, 'account' => $accountId, 'posts' => $postLimit], [
            'workspace' => ['required', 'uuid'],
            'account' => ['nullable', 'uuid'],
            'posts' => ['required', 'integer', 'min:0', 'max:20'],
        ]);

        if ($validation->fails()) {
            $this->error($validation->errors()->first());

            return self::FAILURE;
        }

        if (! Workspace::query()->whereKey($workspaceId)->exists()) {
            $this->error('Workspace not found.');

            return self::FAILURE;
        }

        if (! $settings->metricsEnabled()) {
            $this->error('Analytics collection is disabled. Enable Metrics in instance settings first.');

            return self::FAILURE;
        }

        $hadWorkspace = Context::has('workspace_id');
        $previousWorkspace = Context::get('workspace_id');
        Context::add('workspace_id', $workspaceId);

        try {
            $accounts = ConnectedAccount::query()->when($accountId, fn ($query, $id) => $query->whereKey($id))->limit(101)->get();

            if ($accounts->isEmpty() || $accounts->count() > 100) {
                $this->error('Select an existing account or a workspace with at most 100 accounts.');

                return self::FAILURE;
            }

            $outcomes = [];
            $failed = false;

            foreach ($accounts as $account) {
                if ($account->isDisabled() || $account->status !== ConnectedAccountStatus::Active) {
                    $outcomes[] = ['account_id' => $account->id, 'result' => 'skipped_inactive', 'posts_captured' => 0];

                    continue;
                }

                $postsCaptured = 0;
                $result = 'skipped_polling_disabled';

                try {
                    if ($settings->accountMetricsPollingEnabled($account->platform)) {
                        app()->call([new CaptureAccountMetrics($account), 'handle']);
                        $result = $account->fresh()?->metrics_status->value ?? 'missing';
                        $failed = $failed || in_array($result, ['failed', 'rate_limited', 'missing'], true);
                    }

                    if ((int) $postLimit > 0 && $settings->postMetricsPollingEnabled($account->platform)) {
                        $targets = PostTarget::query()
                            ->whereHas('post')
                            ->where('connected_account_id', $account->id)
                            ->where('status', PostTargetStatus::Published)
                            ->whereNotNull('remote_id')
                            ->orderByDesc('posted_at')
                            ->limit((int) $postLimit)
                            ->get();

                        foreach ($targets as $target) {
                            if ($target->publicationStatus() !== PostTargetStatus::Published) {
                                continue;
                            }

                            app()->call([new CapturePostTargetMetrics($target), 'handle']);
                            $status = $target->fresh()?->metrics_status;
                            $failed = $failed || in_array($status, [null, MetricsStatus::Failed, MetricsStatus::RateLimited], true);
                            $postsCaptured += $status === MetricsStatus::Ok ? 1 : 0;
                        }
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $result = 'capture_failed';
                    $failed = true;
                }

                $outcomes[] = ['account_id' => $account->id, 'result' => $result, 'posts_captured' => $postsCaptured];
            }

            $data = [
                'workspace_id' => $workspaceId,
                'verified_at' => now()->toIso8601String(),
                'outcomes' => $outcomes,
                'accounts' => $analytics->accounts(100, accountId: $accountId)['data'],
            ];

            if ($this->option('json')) {
                $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } else {
                $this->table(['Account ID', 'Result', 'Posts captured'], $outcomes);
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        } finally {
            if (! $hadWorkspace) {
                Context::forget('workspace_id');
            } else {
                Context::add('workspace_id', $previousWorkspace);
            }
        }
    }
}
