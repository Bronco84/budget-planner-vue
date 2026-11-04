<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Telescope records only failures outside local; keep two days of them.
Schedule::command('telescope:prune --hours=48')->daily();

// Retry removing files left unattached by a failed S3 delete (account, budget,
// transaction or attachment deletion), so their S3 objects are not kept forever.
Schedule::command('files:cleanup-orphans')->daily();

// The database cache store never removes an expired row whose key is not read
// again (e.g. replaced projection cache versions); sweep them daily.
Schedule::command('cache:prune-expired')->daily();
