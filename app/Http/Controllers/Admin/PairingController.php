<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppToken;
use App\Models\PairingCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Appairage d'une application à un jeton, sans jamais exposer le jeton dans un QR.
 *
 * Le bouton « Connecter » transmet le MÊME jeton (sans le régénérer) : plusieurs appareils
 * peuvent donc être appairés au même jeton et fonctionner simultanément. On crée un code
 * d'appairage à usage unique (10 min) qui mémorise ce jeton chiffré, et on renvoie au
 * navigateur le code + l'endpoint pour fabriquer le QR. L'application scanne le QR puis
 * réclame le jeton via l'endpoint public /api/v1/pairing/claim.
 *
 * Protégé par re-saisie du mot de passe administrateur à chaque affichage.
 */
class PairingController extends Controller
{
    /** Durée de vie d'un code d'appairage. */
    private const TTL_MINUTES = 10;

    /** POST /admin/jetons/{token}/pairing — génère un code d'appairage (mot de passe requis). */
    public function create(Request $request, AppToken $token): JsonResponse
    {
        $request->validate(['password' => 'required|string']);

        // Re-authentification : le mot de passe de l'admin connecté est redemandé à chaque fois.
        if (! Hash::check($request->string('password'), $request->user()->password)) {
            return response()->json(['message' => 'Mot de passe incorrect.'], 422);
        }

        if (! $token->is_active) {
            return response()->json(['message' => 'Ce jeton est révoqué : créez-en un nouveau.'], 422);
        }

        // On transmet le MÊME jeton (pas de régénération) : plusieurs appareils peuvent donc
        // être appairés au même jeton et fonctionner en même temps. Régénérer la clé reste
        // l'action explicite qui les déconnecte tous.
        $plainToken = $token->plainToken();

        // Jetons créés avant le stockage chiffré : pas de clair disponible → il faut
        // régénérer une fois pour les amorcer (le QR ne peut pas transmettre un clair perdu).
        if ($plainToken === null) {
            return response()->json([
                'message' => "Ce jeton date d'avant l'appairage : régénérez-le une fois (les appareils déjà connectés devront rescanner), puis réessayez.",
                'needs_regeneration' => true,
            ], 409);
        }

        // Un seul code d'appairage en attente par jeton : on remplace le précédent.
        PairingCode::where('app_token_id', $token->id)->whereNull('consumed_at')->delete();

        $plainCode = Str::random(48);

        PairingCode::create([
            'app_token_id' => $token->id,
            'code' => PairingCode::hashCode($plainCode),
            'encrypted_token' => $plainToken,   // chiffré au repos (cast encrypted)
            'created_by' => $request->user()->id,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return response()->json([
            'endpoint' => rtrim(url('/'), '/'),
            'code' => $plainCode,
            'expiresInSeconds' => self::TTL_MINUTES * 60,
        ]);
    }
}
