<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduled Maritime Intelligence Routines
Schedule::command('providers:health')
    ->hourly()
    ->withoutOverlapping()
    ->description('Perform automated health check across all registered data providers');

Schedule::command('encounters:detect --hours=24')
    ->everyFourHours()
    ->withoutOverlapping()
    ->description('Run spatio-temporal encounter proximity analysis on active AIS tracks');

Schedule::command('providers:sync')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->description('Synchronize data from enabled maritime intelligence providers');

Schedule::command('providers:sync ais --limit=25')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->description('Consume batch of live AIS telemetry to maintain real-time track freshness');

Schedule::command('backup:create')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->description('Execute automated PostgreSQL database dump, archive SAR storage, and generate health manifest');

Schedule::command('gfw:vessels:enrich --batch --queue --limit=50')
    ->everySixHours()
    ->withoutOverlapping()
    ->description('Automatically enrich eligible unenriched vessels via Global Fishing Watch API v3');

Schedule::command('ais:prune-telemetry')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->description('Prune AIS historical telemetry according to vessel type retention policies (Fishing is permanently protected)');


