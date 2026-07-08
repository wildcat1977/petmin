<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Hazdar 外部資料整合排程（spec §5）。
 * 每支 command 可獨立手動執行；失敗記 log + 健康狀態，禁止默默跳過。
 * runInBackground 避免單一來源逾時拖慢整輪排程。
 */
Schedule::command('hazdar:fetch-cwa-observations')->everyFiveMinutes()->runInBackground();
Schedule::command('hazdar:fetch-ncdr-alerts')->everyFiveMinutes()->runInBackground();
Schedule::command('hazdar:fetch-radar-echo')->everyTenMinutes()->runInBackground();
Schedule::command('hazdar:sync-shelters')->dailyAt('05:10')->runInBackground();
Schedule::command('hazdar:fetch-pump-status')->everyTenMinutes()->runInBackground();
Schedule::command('hazdar:sync-floodgates')->dailyAt('05:20')->runInBackground();
Schedule::command('hazdar:fetch-transit-alerts')->everyFiveMinutes()->runInBackground();
Schedule::command('hazdar:prune-weather')->dailyAt('03:30');
