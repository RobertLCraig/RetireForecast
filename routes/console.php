<?php

use App\Export\ScenarioExport;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('exports:prune', function (ScenarioExport $export) {
    $this->info("Deleted {$export->prune()} export archive(s) older than a day.");
})->purpose('Delete "export all to PDF" archives older than a day');

Schedule::command('exports:prune')->hourly();
