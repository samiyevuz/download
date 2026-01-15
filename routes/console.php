<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Schedule cleanup of orphaned files every hour
Schedule::job(new \App\Jobs\CleanupOrphanedFilesJob())
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('cleanup-orphaned-files');

// Schedule zombie process cleanup every 30 minutes
Schedule::command('process:cleanup-zombies --kill')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('cleanup-zombie-processes');
