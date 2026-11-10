<?php

use App\Jobs\RefreshEbpTokens;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tâches planifiées de la gateway
|--------------------------------------------------------------------------
*/

// Renouvellement des tokens EBP (tous les comptes). Large marge sur les 30 j.
Schedule::job(new RefreshEbpTokens)->everySixHours();

// Purge des logs et résultats de jobs au-delà de la rétention (360 j).
Schedule::command('ebp:logs:purge')->dailyAt('03:30');
