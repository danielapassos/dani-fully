<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PostTarget;
use App\Services\Publishing\TikTokInboxReconciliation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileTikTokInbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 300;

    public function __construct(public PostTarget $target) {}

    public function uniqueId(): string
    {
        return $this->target->id;
    }

    public function handle(TikTokInboxReconciliation $reconciliation): void
    {
        $reconciliation->reconcile($this->target);
    }
}
