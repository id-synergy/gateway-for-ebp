<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PairingCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Réclamation d'un code d'appairage par une application (public).
 *
 * L'application a scanné un QR contenant un code court-vivant (10 min, usage unique).
 * Elle le POST ici ; si le code est valide, on lui renvoie le vrai jeton d'app (par HTTPS),
 * puis le code est consommé et le jeton chiffré détruit. Un QR réclamé ou expiré ne sert
 * plus à rien.
 */
class PairingController extends Controller
{
    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|max:64']);

        // Transaction + verrou : empêche deux réclamations concurrentes du même code.
        $result = DB::transaction(function () use ($data) {
            $pairing = PairingCode::where('code', PairingCode::hashCode($data['code']))
                ->lockForUpdate()
                ->first();

            if (! $pairing || ! $pairing->isValid()) {
                return null;
            }

            $token = $pairing->encrypted_token;      // déchiffré par le cast
            $appToken = $pairing->appToken;

            // Consommé : le code ne sert plus, et le clair chiffré est effacé.
            $pairing->update(['consumed_at' => now(), 'encrypted_token' => '']);

            return [
                'token' => $token,
                'endpoint' => rtrim(url('/'), '/'),
                'app' => $appToken?->name,
            ];
        });

        if ($result === null) {
            return response()->json(['message' => 'Code d\'appairage invalide ou expiré.'], 410);
        }

        return response()->json($result);
    }
}
