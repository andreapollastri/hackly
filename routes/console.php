<?php

use App\Console\Commands\HacklyCleanupOutputsCommand;
use App\Console\Commands\HacklyDigestCommand;
use App\Console\Commands\HacklyUpdateDatabasesCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(HacklyCleanupOutputsCommand::class)->hourly();

Schedule::command(HacklyDigestCommand::class)
    ->weeklyOn(0, (string) config('hackly.digest.time', '08:00'))
    ->timezone((string) config('hackly.digest.timezone', 'UTC'))
    ->when(fn (): bool => (bool) config('hackly.digest.enabled'));

Schedule::command(HacklyUpdateDatabasesCommand::class)
    ->dailyAt((string) config('hackly.databases.time', '03:30'))
    ->timezone((string) config('hackly.databases.timezone', 'UTC'))
    ->withoutOverlapping(180)
    ->when(fn (): bool => (bool) config('hackly.databases.enabled'));
