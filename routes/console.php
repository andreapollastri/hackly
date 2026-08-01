<?php

use App\Console\Commands\HacklyCleanupOutputsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(HacklyCleanupOutputsCommand::class)->hourly();

Schedule::command('hackly:hourly-repos')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('hackly:nightly')
    ->dailyAt((string) config('hackly.repo.nightly_at', '02:30'))
    ->timezone((string) config('hackly.repo.nightly_timezone', config('app.timezone', 'UTC')))
    ->withoutOverlapping();
