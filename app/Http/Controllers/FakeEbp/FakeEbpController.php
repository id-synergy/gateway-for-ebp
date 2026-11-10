<?php

namespace App\Http\Controllers\FakeEbp;

use App\Http\Controllers\Controller;
use App\Services\Ebp\FakeEbpData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Serveur EBP SIMULÉ (mode démo) — exposition HTTP des données de {@see FakeEbpData}.
 *
 * Imite les endpoints de l'API EBP SaaS Gestion avec des données factices, pour inspecter
 * les réponses en appel direct. Le flux interne de la gateway, lui, n'appelle PAS ces routes :
 * les services EBP court-circuitent vers FakeEbpData quand environment = 'demo' (pas de boucle réseau).
 * Ne doit JAMAIS être exposé en production.
 */
class FakeEbpController extends Controller
{
    /** OAuth : page d'autorisation simulée — renvoie direct un code au callback. */
    public function authorize(Request $request): RedirectResponse
    {
        $redirect = $request->query('redirect_uri');
        $state = $request->query('state');

        return redirect()->away($redirect.'?code=DEMO_CODE&state='.$state);
    }

    /** OAuth : échange code/refresh contre des tokens simulés. */
    public function token(Request $request): JsonResponse
    {
        return response()->json(FakeEbpData::tokenResponse());
    }

    /** GET /fake-ebp/gescom/api/v1/Folders — dossiers simulés. */
    public function folders(): JsonResponse
    {
        return response()->json(FakeEbpData::folders());
    }

    /** GET .../Folders/{folder}/Clients — clients simulés. */
    public function clients(Request $request, string $folder): JsonResponse
    {
        return response()->json(FakeEbpData::clients());
    }

    /** GET .../Folders/{folder}/Items... — articles simulés. */
    public function items(Request $request, string $folder): JsonResponse
    {
        return response()->json(FakeEbpData::items());
    }

    /** GET .../Folders/{folder}/Documents/{type} — documents de vente/achat simulés. */
    public function documents(Request $request, string $folder, string $type): JsonResponse
    {
        return response()->json(FakeEbpData::documents());
    }
}
