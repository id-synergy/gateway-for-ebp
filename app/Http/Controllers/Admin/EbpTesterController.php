<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\Ebp\EbpApiException;
use App\Http\Controllers\Controller;
use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Services\Ebp\EbpClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Testeur de requêtes EBP (back-office) : émet de vraies requêtes authentifiées vers
 * l'API EBP via {@see EbpClient::send()} et renvoie la réponse brute pour le débogage.
 *
 * Volontairement autonome : ne passe PAS par le proxy transparent ni par la liste blanche
 * des préfixes — le but est justement de pouvoir tester n'importe quel endpoint EBP.
 * L'appel reste borné au host EBP du compte (composé par EbpClient à partir de apiBaseUrl()).
 */
class EbpTesterController extends Controller
{
    /** Verbes autorisés par le testeur. */
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** Verbes qui écrivent dans EBP : exigent une confirmation explicite côté UI. */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function run(Request $request, EbpClient $client): JsonResponse
    {
        $data = $request->validate([
            'ebp_account_id' => 'required|integer|exists:ebp_accounts,id',
            'folder_id' => 'nullable|integer',
            'method' => 'required|string|in:'.implode(',', self::METHODS),
            'endpoint' => 'required|string|max:2048',
            'query' => 'nullable|array',
            'body' => 'nullable',
            'confirmed' => 'nullable|boolean',
        ]);

        $method = strtoupper($data['method']);

        // Garde-fou serveur : une écriture ne part jamais sans confirmation explicite,
        // même si l'UI était contournée. Les GET passent sans confirmation.
        if (in_array($method, self::WRITE_METHODS, true) && ! ($data['confirmed'] ?? false)) {
            throw ValidationException::withMessages([
                'confirmed' => 'Cette opération modifie des données réelles dans EBP et doit être confirmée.',
            ]);
        }

        $account = EbpAccount::findOrFail($data['ebp_account_id']);

        // Dossier optionnel : doit appartenir au compte choisi (sinon on l'ignore).
        $folder = null;
        if (! empty($data['folder_id'])) {
            $folder = EbpFolder::where('ebp_account_id', $account->id)
                ->where('id', $data['folder_id'])
                ->first();
        }

        $endpoint = ltrim(trim($data['endpoint']), '/');
        $query = $this->normalizeQuery($data['query'] ?? []);
        $body = $this->decodeBody($method, $data['body'] ?? null);

        // URL finale réellement appelée (host EBP du compte + endpoint), pour affichage.
        $finalUrl = rtrim($account->apiBaseUrl(), '/').'/'.$endpoint;

        $startedAt = microtime(true);

        try {
            $result = $client->send($account, $folder, $method, $endpoint, $query, $body);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            return response()->json([
                'ok' => true,
                'status' => $result['status'],
                'durationMs' => $durationMs,
                'url' => $finalUrl,
                'method' => $method,
                'body' => $this->prettyBody($result['raw']),
            ]);
        } catch (EbpApiException $e) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            // Erreur EBP (4xx/5xx) : on renvoie quand même le corps, c'est le cœur du débogage.
            return response()->json([
                'ok' => false,
                'status' => $e->status,
                'durationMs' => $durationMs,
                'url' => $finalUrl,
                'method' => $method,
                'body' => $this->prettyBody($e->body),
            ]);
        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            // Panne réseau / transport : pas de réponse HTTP d'EBP.
            return response()->json([
                'ok' => false,
                'status' => 0,
                'durationMs' => $durationMs,
                'url' => $finalUrl,
                'method' => $method,
                'body' => null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Normalise les paramètres de query : ignore les clés vides, garde les valeurs telles quelles.
     *
     * @param  array<string,mixed>  $query
     * @return array<string,mixed>
     */
    private function normalizeQuery(array $query): array
    {
        $out = [];
        foreach ($query as $key => $value) {
            $key = is_string($key) ? trim($key) : $key;
            if ($key === '' || $key === null) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Prépare le corps pour les écritures : une chaîne JSON saisie est décodée en structure,
     * une structure déjà fournie est conservée. Les GET n'ont pas de corps.
     */
    private function decodeBody(string $method, mixed $body): mixed
    {
        if (in_array($method, ['GET', 'DELETE'], true) || $body === null || $body === '') {
            return null;
        }

        if (is_string($body)) {
            $decoded = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw ValidationException::withMessages([
                    'body' => 'Le corps JSON est invalide : '.json_last_error_msg(),
                ]);
            }

            return $decoded;
        }

        return $body;
    }

    /**
     * Rend le corps de réponse lisible (JSON indenté quand c'est du JSON), sinon brut.
     */
    private function prettyBody(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return $raw;
        }

        $decoded = json_decode($raw, true);

        return $decoded !== null
            ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : $raw;
    }
}
