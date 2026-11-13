<?php

use App\Http\Controllers\Admin\AdminActionController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\ApiDocController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\ConnexionLogController;
use App\Http\Controllers\Admin\EbpTesterController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\IpController;
use App\Http\Controllers\Admin\PairingController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Auth\AcceptInvitationController;
use App\Http\Controllers\FakeEbp\FakeEbpController;
use App\Http\Controllers\HealthcheckController;
use App\Http\Controllers\OAuth\EbpOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));

// Acceptation d'une invitation (public) : création de compte via un lien à durée limitée.
Route::get('/invitation/{token}', [AcceptInvitationController::class, 'show'])->name('invitation.show');
Route::post('/invitation/{token}', [AcceptInvitationController::class, 'store'])->name('invitation.store');

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
        Route::get('/connect/authorize', [FakeEbpController::class, 'authorize']);
        Route::post('/connect/authorize', [FakeEbpController::class, 'authorizeSubmit']);
        Route::post('/connect/token', [FakeEbpController::class, 'token']);
        Route::get('/gescom/api/v1/Folders', [FakeEbpController::class, 'folders']);
        Route::get('/gescom/api/v1/Folders/{folder}/Clients', [FakeEbpController::class, 'clients']);
        Route::get('/gescom/api/v1/Folders/{folder}/Items/{any?}', [FakeEbpController::class, 'items'])->where('any', '.*');
        Route::get('/gescom/api/v1/Folders/{folder}/Documents/{type}', [FakeEbpController::class, 'documents']);
    });
}

/*
|--------------------------------------------------------------------------
| Back-office (Inertia + Vue, vraies données)
|--------------------------------------------------------------------------
*/
// block.ip rejette (403) toute IP en liste noire, y compris sur le back-office.
// Garde-fou : les boucles locales (127.0.0.1, ::1) ne sont JAMAIS bloquées
// (cf. IpAccessControl::isBlocked), pour ne pas verrouiller l'accès admin.
// track.session (observateur best-effort) est placé AVANT auth : il doit pouvoir
// constater qu'un admin anciennement connecté revient après expiration de sa session
// (auth le redirigerait ensuite vers /login). Il n'OBSERVE que, ne bloque jamais.
Route::prefix('admin')->middleware(['block.ip', 'track.session', 'auth', '2fa'])->group(function () {
    Route::get('/', [AdminController::class, 'dashboard']);
    Route::get('/comptes', [AdminController::class, 'comptes'])->name('admin.comptes.index');
    Route::post('/comptes', [AdminActionController::class, 'storeAccount'])->name('admin.comptes.store');
    Route::get('/comptes/{account}', [AdminController::class, 'compte'])->name('admin.comptes.show');
    Route::get('/comptes/{account}/events', [AdminController::class, 'accountEvents'])->name('admin.comptes.events');
    Route::patch('/comptes/{account}', [AdminActionController::class, 'updateAccount'])->name('admin.comptes.update');
    Route::delete('/comptes/{account}', [AdminActionController::class, 'destroyAccount'])->name('admin.comptes.destroy');
    Route::post('/comptes/{account}/sync-folders', [AdminActionController::class, 'syncFolders'])->name('admin.comptes.sync-folders');
    Route::post('/comptes/{account}/manual-token', [EbpOAuthController::class, 'manualExchange'])->name('admin.comptes.manual-token');
    Route::get('/jetons', [AdminController::class, 'jetons']);
    Route::get('/admins', [AdminController::class, 'admins'])->name('admin.admins.index');
    Route::get('/admins/{user}', [AdminController::class, 'admin'])->name('admin.admins.show');
    Route::get('/connexions', [ConnexionLogController::class, 'index'])->name('admin.connexions.index');
    Route::get('/audit', [AuditController::class, 'index'])->name('admin.audit.index');
    Route::get('/ip', [IpController::class, 'index'])->name('admin.ip.index');
    Route::get('/logs', [AdminController::class, 'logs']);
    Route::get('/logs/{log}', [AdminController::class, 'log'])->name('admin.logs.show');
    Route::get('/testeur', [AdminController::class, 'testeur'])->name('admin.testeur');
    Route::get('/testeur/suggestions', [EbpTesterController::class, 'suggestions'])->name('admin.testeur.suggestions');
    Route::post('/testeur/run', [EbpTesterController::class, 'run'])->name('admin.testeur.run');
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
    Route::post('/jetons/{token}/pairing', [PairingController::class, 'create'])->name('admin.tokens.pairing');
    Route::put('/jetons/{token}/regles', [AdminActionController::class, 'updateTokenRules'])->name('admin.tokens.rules');
    Route::post('/ip', [IpController::class, 'store'])->name('admin.ip.store');
    Route::patch('/ip/{ip}', [IpController::class, 'update'])->name('admin.ip.update');
    Route::delete('/ip/{ip}', [IpController::class, 'destroy'])->name('admin.ip.destroy');
    Route::post('/admins', [AdminActionController::class, 'storeAdmin'])->name('admin.admins.store');
    Route::patch('/admins/{user}/toggle', [AdminActionController::class, 'toggleAdmin'])->name('admin.admins.toggle');
    Route::post('/invitations', [InvitationController::class, 'store'])->name('admin.invitations.store');
    Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy'])->name('admin.invitations.destroy');
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
