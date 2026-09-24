<?php

use App\Console\Commands\CaptureMetrics;
use App\Console\Commands\DispatchDueMessageFetches;
use App\Console\Commands\DispatchDuePosts;
use App\Console\Commands\DispatchDueReplyFetches;
use App\Console\Commands\DispatchDueReposts;
use App\Console\Commands\PollNativePosts;
use App\Console\Commands\PruneAbandonedUploads;
use App\Console\Commands\PruneMcpBindings;
use App\Console\Commands\PruneUsageEvents;
use App\Console\Commands\ReconcileSyncFanOut;
use App\Console\Commands\ReconcileTikTokInboxes;
use App\Console\Commands\ReconcileUsageCounters;
use App\Console\Commands\RefreshCommunityStats;
use App\Console\Commands\RefreshExpiringTokens;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(DispatchDuePosts::class)->everyMinute()->onOneServer()->withoutOverlapping();
Schedule::command(RefreshExpiringTokens::class)->everyFifteenMinutes()->onOneServer()->withoutOverlapping();
Schedule::command(PruneMcpBindings::class)->hourly()->onOneServer();
Schedule::command(PruneAbandonedUploads::class)->hourly()->onOneServer();
Schedule::command(ReconcileTikTokInboxes::class)->everyFifteenMinutes()->onOneServer()->withoutOverlapping();

Schedule::command(CaptureMetrics::class)->everyFifteenMinutes()->onOneServer()->withoutOverlapping();

Schedule::command(DispatchDueReplyFetches::class)->everyFifteenMinutes()->onOneServer()->withoutOverlapping();

Schedule::command(DispatchDueMessageFetches::class)->everyFifteenMinutes()->onOneServer()->withoutOverlapping();

if (config('repost.enabled')) {
    Schedule::command(DispatchDueReposts::class)->everyFifteenMinutes()->onOneServer()->withoutOverlapping();
}

if (config('sync.enabled')) {
    Schedule::command(ReconcileSyncFanOut::class)->everyFiveMinutes()->onOneServer()->withoutOverlapping();
    Schedule::command(PollNativePosts::class)->everyFifteenMinutes()->onOneServer()->withoutOverlapping();
}

Schedule::command(ReconcileUsageCounters::class)->dailyAt('02:10')->onOneServer()->withoutOverlapping();
Schedule::command(PruneUsageEvents::class)->dailyAt('02:20')->onOneServer();

if (! config('subscriptions.enabled')) {
    Schedule::command(RefreshCommunityStats::class)->daily()->onOneServer()->withoutOverlapping();
}
