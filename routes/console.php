<?php

use Illuminate\Foundation\Inspiring;
use App\Services\QrTokenService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Single-use QR check-in tokens live for 60 seconds; clear out old rows daily.
Schedule::call(fn () => app(QrTokenService::class)->prune())->daily();

// Delete API session tokens that expired more than a day ago.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
