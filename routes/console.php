<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


Schedule::command('fx:fetch-rates --base=USD --quote=GBP')
    ->dailyAt('06:30')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('shop:monitor-snapshot-thresholds')->dailyAt('02:30');
Schedule::command('shop:reconcile')->dailyAt('03:00');
Schedule::command('shop:prune-snapshots')->weeklyOn(0, '04:00');
Schedule::command('shop:prune-personalisation-orphans')->dailyAt('04:15')->onOneServer()->withoutOverlapping();
Schedule::command('shop:expire-pending-orders')->everyFiveMinutes();

Schedule::command('site:prune-page-revisions')->weeklyOn(0, '04:30');

Schedule::command('editor:prune-operation-log')->daily()->onOneServer()->withoutOverlapping();

Schedule::command('media:purge-provisional')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();
