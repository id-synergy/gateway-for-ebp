<?php

use App\Http\Controllers\Admin\AdminActionController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\ApiDocController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\HealthcheckController;
use App\Http\Controllers\OAuth\EbpOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));

// Contrôle de santé pour la supervision (Uptime Kuma) : public = ok/ko,
// détail par brique avec ?token=HEALTHCHECK_TOKEN.
Route::get('/healthcheck', HealthcheckController::class)->name('healthcheck');

// /login, /two-factor-challenge, /logout : fournis par Fortify (vues Inertia configurées).

/*
|--------------------------------------------------------------------------
| Serveur EBP SIMULÉ (mode démo) — jamais actif en production
|--------------------------------------------------------------------------
| Permet de tester tout le flux de la gateway sans identifiants EBP réels.
| Un compte avec environment='demo' tape sur ces routes au lieu de l'API EBP.
*/
if (! app()->environment('production')) {
    Route::prefix('fake-ebp')->group(function () {
        Route::get('/connect/authorize', [\App\Http\Controllers\FakeEbp\FakeEbpController::class, 'authorize']);
        Route::post('/connect/authorize', [\App\Http\Controllers\FakeEbp\FakeEbpController::class, 'authorizeSubmit']);
        Route::post('/connect/token', [\App\Http\Controllers\FakeEbp\FakeEbpController::class, 'token']);
        Route::get('/gescom/api/v1/Folders', [\App\Http\Controllers\FakeEbp\FakeEbpController::class, 'folders']);
        Route::get('/gescom/api/v1/Folders/{folder}/Clients', [\App\Http\Controllers\FakeEbp\FakeEbpController::class, 'clients']);
        Route::get('/gescom/api/v1/Folders/{folder}/Items/{any?}', [\App\Http\Controllers\FakeEbp\FakeEbpController::class, 'items'])->where('any', '.*');
        Route::get('/gescom/api/v1/Folders/{folder}/Documents/{type}', [\App\Http\Controllers\FakeEbp\FakeEbpController::class, 'documents']);
    });
}

/*
|--------------------------------------------------------------------------
| Back-office (Inertia + Vue, vraies données)
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->middleware(['auth', '2fa'])->group(function () {
    Route::get('/', [AdminController::class, 'dashboard']);
    Route::get('/comptes', [AdminController::class, 'comptes'])->name('admin.comptes.index');
    Route::post('/comptes', [AdminActionController::class, 'storeAccount'])->name('admin.comptes.store');
    Route::get('/comptes/{account}', [AdminController::class, 'compte'])->name('admin.comptes.show');
    Route::get('/comptes/{account}/events', [AdminController::class, 'accountEvents'])->name('admin.comptes.events');
    Route::patch('/comptes/{account}', [AdminActionController::class, 'updateAccount'])->name('admin.comptes.update');
    Route::delete('/comptes/{account}', [AdminActionController::class, 'destroyAccount'])->name('admin.comptes.destroy');
    Route::post('/comptes/{account}/sync-folders', [AdminActionController::class, 'syncFolders'])->name('admin.comptes.sync-folders');
    Route::post('/comptes/{account}/manual-token', [\App\Http\Controllers\OAuth\EbpOAuthController::class, 'manualExchange'])->name('admin.comptes.manual-token');
    Route::get('/jetons', [AdminController::class, 'jetons']);
    Route::get('/admins', [AdminController::class, 'admins']);
    Route::get('/ip', [AdminController::class, 'ip']);
    Route::get('/logs', [AdminController::class, 'logs']);
    Route::get('/logs/{log}', [AdminController::class, 'log'])->name('admin.logs.show');
    Route::get('/jobs', [AdminController::class, 'jobs']);
    Route::get('/jobs/{job:public_id}', [AdminController::class, 'job'])->name('admin.jobs.show');
    Route::get('/supervision', [AdminController::class, 'supervision'])->name('admin.supervision');
    Route::get('/api', [ApiDocController::class, 'index'])->name('admin.api');
    Route::get('/api/download/gateway', [ApiDocController::class, 'downloadGateway'])->name('admin.api.download.gateway');
    Route::get('/api/download/ebp', [ApiDocController::class, 'downloadEbpReference'])->name('admin.api.download.ebp');
    Route::get('/profil', [ProfileController::class, 'show'])->name('admin.profile');

    // Actions.
    Route::post('/jetons', [AdminActionController::class, 'storeToken'])->name('admin.tokens.store');
    Route::delete('/jetons/{token}', [AdminActionController::class, 'revokeToken'])->name('admin.tokens.revoke');
    Route::post('/jetons/{token}/regenerate', [AdminActionController::class, 'regenerateToken'])->name('admin.tokens.regenerate');
    Route::post('/ip', [AdminActionController::class, 'storeIp'])->name('admin.ip.store');
    Route::delete('/ip/{ip}', [AdminActionController::class, 'destroyIp'])->name('admin.ip.destroy');
    Route::post('/admins', [AdminActionController::class, 'storeAdmin'])->name('admin.admins.store');
    Route::patch('/admins/{user}/toggle', [AdminActionController::class, 'toggleAdmin'])->name('admin.admins.toggle');
    Route::post('/preferences', [AdminActionController::class, 'togglePreference'])->name('admin.preferences.toggle');
    Route::post('/comptes/{account}/clear-cache', [AdminActionController::class, 'clearAccountCache'])->name('admin.comptes.clear-cache');
    Route::post('/cache/clear', [AdminActionController::class, 'clearAllCache'])->name('admin.cache.clear');
});

/*
|--------------------------------------------------------------------------
| OAuth EBP (back-office admin)
|--------------------------------------------------------------------------
| connect  : démarre la connexion d'un compte EBP — réservé à un admin connecté.
| callback : URL whitelistée chez EBP (ne pas changer le chemin) — public car c'est EBP qui y revient ;
|            la vérification du `state` en session empêche tout callback forgé.
*/
Route::get('/oauth/ebp/connect', [EbpOAuthController::class, 'connect'])->middleware('auth')->name('ebp.oauth.connect');
Route::get('/oauth/ebp/callback', [EbpOAuthController::class, 'callback'])->name('ebp.oauth.callback');
