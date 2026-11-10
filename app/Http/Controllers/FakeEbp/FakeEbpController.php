<?php

namespace App\Http\Controllers\FakeEbp;

use App\Http\Controllers\Controller;
use App\Services\Ebp\FakeEbpData;
use Illuminate\Contracts\View\View;
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
    /**
     * OAuth : page de connexion EBP SIMULÉE. Affiche un formulaire de login factice
     * (n'importe quelle saisie est acceptée — c'est seulement pour dérouler le flux),
     * avec les scénarios Autoriser / Refuser / Identifiants invalides.
     */
    public function authorize(Request $request): View
    {
        return view('fake-ebp.authorize', [
            'redirectUri' => (string) $request->query('redirect_uri'),
            'state' => (string) $request->query('state'),
            'clientId' => (string) $request->query('client_id'),
            'scope' => (string) $request->query('scope'),
        ]);
    }

    /**
     * Traite la soumission du faux portail selon le bouton choisi :
     *  - authorize : succès -> redirige vers le callback avec un code et le state ;
     *  - deny      : refus  -> redirige vers le callback avec error=access_denied (pas de code) ;
     *  - invalid   : identifiants « refusés » -> ré-affiche la page avec un message.
     */
    public function authorizeSubmit(Request $request): RedirectResponse|View
    {
        $redirect = (string) $request->input('redirect_uri');
        $state = (string) $request->input('state');
        $decision = (string) $request->input('decision', 'authorize');

        if ($decision === 'invalid') {
            return view('fake-ebp.authorize', [
                'redirectUri' => $redirect,
                'state' => $state,
                'clientId' => (string) $request->input('client_id'),
                'scope' => (string) $request->input('scope'),
                'error' => 'Identifiants EBP refusés (simulation). Réessayez.',
            ]);
        }

        if ($decision === 'deny') {
            // L'utilisateur refuse le consentement : retour sans code, comme le vrai portail.
            return redirect()->away($redirect.'?error=access_denied&state='.urlencode($state));
        }

        // Succès : n'importe quelle saisie est acceptée, on renvoie un code d'autorisation.
        return redirect()->away($redirect.'?code=DEMO_CODE&state='.urlencode($state));
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
