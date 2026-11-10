<?php

use App\Http\Controllers\Api\ComposeController;
use App\Http\Controllers\Api\JobsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Gateway (consommée par les apps clientes)
|--------------------------------------------------------------------------
| Deux familles de routes :
|
| Fonctions DE LA GATEWAY, sous /api/v1 (santé, contexte, jobs, composite).
| Ce ne sont pas des endpoints EBP : elles n'existent que grâce à la gateway.
|
| Le MIROIR des endpoints EBP (chemins /gescom/api/v1/... à la racine) est dans
| routes/mirror.php, monté par bootstrap/app.php. Il garde les chemins EBP officiels
| tels quels : on reste une passerelle, pas un remplaçant.
|
| Rate-limit applicatif (throttle:gateway) : IP autorisées illimitées, reste bridé (429).
| Auth par jeton d'app (app.token) : résout compte EBP + dossier.
*/
Route::prefix('v1')->middleware('throttle:gateway')->group(function () {

    // Santé — public (sert aussi à vérifier le rate-limit).
    Route::get('/ping', fn () => response()->json(['ok' => true, 'service' => 'ebp-gateway']));

    // Spécification OpenAPI de la gateway (publique).
    Route::get('/openapi.yaml', function () {
        return response(file_get_contents(base_path('openapi/gateway.yaml')), 200)
            ->header('Content-Type', 'application/yaml');
    });

    Route::middleware('app.token')->group(function () {

        // Écho de contexte : vérifie l'auth + la résolution compte/dossier.
        Route::get('/whoami', function (Request $request) {
            $account = $request->attributes->get('ebp_account');
            $folder = $request->attributes->get('ebp_folder');

            return response()->json([
                'app' => $request->attributes->get('app_token')->name,
                'account' => $account->label,
                // Dossier par défaut du jeton, ou null si le jeton n'en a pas.
                'folder' => $folder ? ['id' => $folder->folder_id, 'label' => $folder->label] : null,
            ]);
        });

        // Suivi des jobs (lectures basculées en asynchrone).
        Route::get('/jobs', [JobsController::class, 'index']);
        Route::get('/jobs/{jobId}', [JobsController::class, 'status']);
        Route::get('/jobs/{jobId}/result', [JobsController::class, 'result']);

        // Requêtes multiples (sous-jobs, batch).
        Route::post('/batch', [ComposeController::class, 'batch']);
        Route::post('/compose', [ComposeController::class, 'compose']);
    });
});
