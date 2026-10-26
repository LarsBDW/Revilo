<?php

use App\Services\InventoryService;
use App\Services\MarketingService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('revilo:expire', function () {
    $this->info(app(InventoryService::class)->expire().' reservations released.');
})->purpose('Release expired inventory reservations');
Artisan::command('revilo:marketing', function () {
    $s = app(MarketingService::class);
    $this->info('Stock notices: '.$s->stock().'; campaign records: '.$s->campaigns().'; cart reminders: '.$s->abandoned().'. Log/array mail drivers create previews only.');
})->purpose('Process opted-in marketing and stock notifications');
Schedule::command('revilo:expire')->everyMinute()->withoutOverlapping();
Schedule::command('revilo:marketing')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('marketplaces:process')->everyMinute()->withoutOverlapping(10);
