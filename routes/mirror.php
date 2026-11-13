<?php

use App\Http\Controllers\Api\EbpProxyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Proxy transparent vers l'API EBP — À LA RACINE
|--------------------------------------------------------------------------
| Une seule route attrape-tout : toute requête (tout verbe) dont le chemin commence
| par un préfixe EBP autorisé (config ebp.gateway.proxy.allowed_prefixes) est relayée
| telle quelle vers EBP. On n'énumère aucun endpoint : la gateway écoute ce qui entre
| et ce qui sort, authentifie, régule le quota, journalise, cache les lectures.
|
| Le contrôleur rejette (404) tout chemin hors des préfixes autorisés, mais on cadre
| déjà la route sur le 1er segment des préfixes connus (contrainte regex) pour ne pas
| avaler les routes de la gateway (/api/v1/...) ni le back-office.
|
| Montée à la racine (pas sous /api) par bootstrap/app.php (then:), avec le groupe
| de middleware « api » : stateless, pas de session ni CSRF web.
*/

// 1er segment des préfixes autorisés (ex. « gescom »), pour cadrer la route attrape-tout.
$roots = collect((array) config('ebp.gateway.proxy.allowed_prefixes', []))
    ->map(fn ($p) => explode('/', trim($p, '/'))[0])
    ->filter()
    ->unique()
    ->values()
    ->all();

$rootPattern = $roots ? implode('|', array_map('preg_quote', $roots)) : 'gescom';

Route::match(
    ['get', 'post', 'put', 'patch', 'delete'],
    '/{path}',
    [EbpProxyController::class, 'handle']
)
    ->where('path', '(?:'.$rootPattern.')/.*')
    ->middleware(['api', 'block.ip', 'throttle:gateway', 'app.token']);
