<?php

use App\Jobs\RefreshEbpTokens;
use App\Models\BlockedRequestEvent;
use App\Models\PairingCode;
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

// Détection d'activité anormale (429, IP bloquées insistantes, échecs de login).
// Cadence recommandée : toutes les 5 min. Avec une fenêtre de 15 min et un cooldown
// de 15 min (config ebp.anomaly), un signal persistant est vu puis mis en silence
// jusqu'à expiration, sans alertes en boucle.
Schedule::command('gateway:detect-anomalies')->everyFiveMinutes()->withoutOverlapping();

// Purge des tentatives bloquées (403 ip_blocked) au-delà de la rétention des logs.
Schedule::call(function () {
    BlockedRequestEvent::where('occurred_at', '<', now()->subDays((int) config('ebp.retention.logs_days')))
        ->delete();
})->dailyAt('03:40');

// Purge des codes d'appairage consommés ou expirés (court-vivants, 10 min).
Schedule::call(function () {
    PairingCode::where('expires_at', '<', now())
        ->orWhereNotNull('consumed_at')
        ->delete();
})->hourly();
