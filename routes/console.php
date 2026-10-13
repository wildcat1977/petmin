<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 需求分數每小時重算（眾包回報持續進來）；失敗記 log，不默默跳過
Schedule::command('demand:recompute')->hourly()->runInBackground();
