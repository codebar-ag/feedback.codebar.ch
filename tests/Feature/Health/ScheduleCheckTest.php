<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Facades\Health;

test('schedule heartbeat is stored in the health cache store', function () {
    $this->artisan('health:schedule-check-heartbeat')->assertSuccessful();

    expect(Cache::store('health')->get('health:checks:schedule:latestHeartbeatAt'))->not->toBeNull();
})->group('feature', 'health');

test('schedule check reads the heartbeat from the health cache store', function () {
    $this->artisan('health:schedule-check-heartbeat')->assertSuccessful();

    $check = collect(Health::registeredChecks())->first(fn ($check) => $check instanceof ScheduleCheck);

    expect($check->run()->status->value)->toBe('ok');
})->group('feature', 'health');

test('flushing the default cache keeps the schedule heartbeat', function () {
    config(['cache.default' => 'database']);

    $this->artisan('health:schedule-check-heartbeat')->assertSuccessful();

    Cache::flush();

    $check = collect(Health::registeredChecks())->first(fn ($check) => $check instanceof ScheduleCheck);

    expect($check->run()->status->value)->toBe('ok');
})->group('feature', 'health');
