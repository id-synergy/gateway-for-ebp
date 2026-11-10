<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Documentation de l'API de la gateway, à destination des apps clientes.
 *
 * Deux vues sur la même source :
 *  - un guide lisible (authentification + endpoints groupés),
 *  - Swagger UI interactif, alimenté par /api/v1/openapi.yaml.
 */
class ApiDocController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/ApiDoc', [
            'openapiUrl' => url('/api/v1/openapi.yaml'),
            'baseUrl' => rtrim(url('/'), '/'),
            'groups' => $this->endpointGroups(),
            'proxyPrefixes' => (array) config('ebp.gateway.proxy.allowed_prefixes', []),
        ]);
    }

    /**
     * Endpoints de la gateway, groupés pour la doc lisible. Chemins complets depuis la racine.
     *
     * Deux familles : les fonctions DE LA GATEWAY sous /api/v1, et le MIROIR des endpoints
     * EBP officiels aux chemins EBP (/gescom/api/v1/...), au FolderId près (implicite).
     * Source de vérité détaillée : openapi/gateway.yaml (servi sur /api/v1/openapi.yaml).
     *
     * @return array<int,array<string,mixed>>
     */
    protected function endpointGroups(): array
    {
        return [
            [
                'title' => 'Santé & contexte',
                'description' => 'Fonctions de la gateway (pas des endpoints EBP). /ping est public.',
                'endpoints' => [
                    ['method' => 'GET', 'path' => '/api/v1/ping', 'auth' => false, 'summary' => 'Ping de la gateway'],
                    ['method' => 'GET', 'path' => '/api/v1/whoami', 'auth' => true, 'summary' => 'Contexte du jeton (app, compte, dossier)'],
                ],
            ],
            [
                'title' => 'Proxy EBP — exemples de chemins',
                'description' => "Proxy transparent : TOUTE requête (tout verbe) dont le chemin commence par un préfixe EBP autorisé est relayée telle quelle vers l'API EBP. On ne liste pas les endpoints — ceux-ci ne sont que des exemples. FolderId implicite (jeton, ou ?folderId=). Lectures mises en cache (bypass ?noCache=1) ; sous charge, bascule en job (202).",
                'endpoints' => [
                    ['method' => 'GET', 'path' => '/gescom/api/v1/Folders', 'auth' => true, 'summary' => 'Dossiers du compte'],
                    ['method' => 'GET', 'path' => '/gescom/api/v1/Clients', 'auth' => true, 'summary' => 'Clients du dossier'],
                    ['method' => 'GET', 'path' => '/gescom/api/v1/Items/ItemsExtentedQuery', 'auth' => true, 'summary' => 'Articles du dossier'],
                    ['method' => 'GET', 'path' => '/gescom/api/v1/Documents/SaleDocument', 'auth' => true, 'summary' => 'Documents de vente'],
                    ['method' => 'POST', 'path' => '/gescom/api/v1/Documents/SaleDocument', 'auth' => true, 'summary' => 'Créer un document de vente (écriture relayée)'],
                ],
            ],
            [
                'title' => 'Jobs',
                'description' => "Suivi des appels basculés en asynchrone. On interroge l'état, puis on récupère le résultat.",
                'endpoints' => [
                    ['method' => 'GET', 'path' => '/api/v1/jobs/{jobId}', 'auth' => true, 'summary' => "État d'un job"],
                    ['method' => 'GET', 'path' => '/api/v1/jobs/{jobId}/result', 'auth' => true, 'summary' => "Résultat d'un job terminé"],
                ],
            ],
            [
                'title' => 'Composite',
                'description' => 'Lancer plusieurs appels en une requête : batch (indépendants) ou compose (sous-jobs liés).',
                'endpoints' => [
                    ['method' => 'POST', 'path' => '/api/v1/batch', 'auth' => true, 'summary' => 'Plusieurs lectures indépendantes en un appel'],
                    ['method' => 'POST', 'path' => '/api/v1/compose', 'auth' => true, 'summary' => 'Enchaînement de sous-jobs liés'],
                ],
            ],
        ];
    }
}
