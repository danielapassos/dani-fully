<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\PostTargetRetryRejected;
use App\Models\PostTarget;
use App\Services\Publishing\TikTokAccountsRecovery;
use Illuminate\Console\Command;
use Throwable;

class RecoverTikTokAccounts extends Command
{
    protected $signature = 'tiktok:recover-accounts {target : Exact failed PostTarget ID to recover without publishing}';

    protected $description = 'Verify a rejected native TikTok submission and prepare the same target for an explicit Accounts API retry.';

    public function handle(TikTokAccountsRecovery $recovery): int
    {
        $target = PostTarget::query()->find((string) $this->argument('target'));
        if ($target === null) {
            $this->error('The requested post target does not exist. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $recovered = $recovery->recover($target);
        } catch (PostTargetRetryRejected $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Recovery verification failed. The original target was preserved; check the Accounts API connection and original submission before trying again.');

            return self::FAILURE;
        }

        $this->info('Verified and recovered target '.$recovered->id.' for TikTok Accounts API.');
        $this->line('Nothing was published or queued. Retry this existing target after reviewing its unchanged caption, video, and Everyone visibility.');

        return self::SUCCESS;
    }
}
