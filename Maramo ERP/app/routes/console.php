<?php

use App\Support\Modules;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduled jobs of the installed packages (see App\Support\Modules::SCHEDULES).
foreach (Modules::SCHEDULES as $marker => $jobs) {
    if (! class_exists($marker)) {
        continue;
    }

    foreach ($jobs as $job) {
        [$command, $frequency] = $job;
        Schedule::command($command)->{$frequency}(...array_slice($job, 2));
    }
}
