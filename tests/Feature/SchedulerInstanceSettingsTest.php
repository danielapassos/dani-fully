<?php

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Jobs\CaptureAccountMetrics;
use App\Models\ConnectedAccount;
use App\Support\InstanceSettings;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;

function registerInstanceSettingSchedules(): Schedule
{
    $schedule = new Schedule;
    ScheduleFacade::swap($schedule);
    require base_path('routes/console.php');

    return $schedule;
}

test('database controlled pollers remain scheduled when environment defaults are off', function (string $command) {
    config([
        'metrics.enabled' => false,
        'engagement.enabled' => false,
        'messages.enabled' => false,
    ]);

    $schedule = registerInstanceSettingSchedules();
    $event = collect($schedule->events())->first(
        fn (Event $event): bool => str_contains((string) $event->command, $command),
    );

    expect($event)->toBeInstanceOf(Event::class)
        ->and($event->expression)->toBe('*/15 * * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
})->with(['metrics:capture', 'engagement:dispatch-due', 'messages:dispatch-due', 'tiktok:reconcile-inbox']);

test('an instance metrics switch starts and stops the already registered capture command', function () {
    config(['metrics.enabled' => false]);
    $schedule = registerInstanceSettingSchedules();
    $event = collect($schedule->events())->first(
        fn (Event $event): bool => str_contains((string) $event->command, 'metrics:capture'),
    );
    expect($event)->toBeInstanceOf(Event::class);

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Instagram,
        'status' => ConnectedAccountStatus::Active,
        'metrics_captured_at' => null,
    ]);
    Queue::fake();
    app(InstanceSettings::class)->update(['metrics_enabled' => true]);

    $this->artisan('metrics:capture')->assertSuccessful();

    Queue::assertPushed(CaptureAccountMetrics::class, fn (CaptureAccountMetrics $job): bool => $job->account->is($account));

    Queue::fake();
    app(InstanceSettings::class)->update(['metrics_enabled' => false]);
    $this->artisan('metrics:capture')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('sync pollers retain single server and overlap protection', function (string $command) {
    config(['sync.enabled' => true]);
    $event = collect(registerInstanceSettingSchedules()->events())->first(
        fn (Event $event): bool => str_contains((string) $event->command, $command),
    );

    expect($event)->toBeInstanceOf(Event::class)
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
})->with(['sync:reconcile', 'sync:poll-native']);
