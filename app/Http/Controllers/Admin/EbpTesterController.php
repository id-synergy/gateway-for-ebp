<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\Ebp\EbpApiException;
use App\Http\Controllers\Controller;
use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Models\RequestLog;
use App\Services\Ebp\EbpClient;
use App\Services\Ebp\EbpEndpointCatalog;
use App\Services\Ebp\EbpPath;
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

    /** Fenêtre de l'historique d'endpoints proposé à l'autocomplétion. */
    private const HISTORY_DAYS = 30;

    /**
     * Suggestions pour l'autocomplétion du champ endpoint :
     *  - « history » : endpoints réellement appelés sur les 30 derniers jours, distincts, avec
     *    la méthode et les derniers paramètres (query / WhereCondition) de chaque endpoint ;
     *  - « catalog » : endpoints EBP concrets issus des specs OpenAPI, groupés par module.
     *
     * Quand un dossier est précisé, {FolderId} est remplacé par son identifiant réel dans les
     * deux sources (le catalogue par substitution, l'historique par normalisation du segment
     * Folders/<id>). Sans dossier, {FolderId} est laissé tel quel.
     */
    public function suggestions(Request $request, EbpEndpointCatalog $catalog): JsonResponse
    {
        $data = $request->validate([
            'account' => 'nullable|integer|exists:ebp_accounts,id',
            'folder' => 'nullable|integer',
        ]);

        // Identifiant EBP du dossier choisi (folder_id), s'il appartient bien au compte.
        $folderId = null;
        if (! empty($data['folder'])) {
            $folder = EbpFolder::query()
                ->when(! empty($data['account']), fn ($q) => $q->where('ebp_account_id', $data['account']))
                ->whereKey($data['folder'])
                ->first();
            $folderId = $folder?->folder_id;
        }

        return response()->json([
            'history' => $this->history($data['account'] ?? null, $folderId),
            'catalog' => $catalog->groupsForFolder($folderId),
        ]);
    }

    /**
     * Endpoints distincts appelés sur la fenêtre d'historique, le plus récent d'abord, avec les
     * derniers paramètres utilisés. Filtrés par compte quand il est fourni.
     *
     * Le corps de requête n'est pas journalisé par la gateway (seul le corps de RÉPONSE l'est) :
     * l'historique pré-remplit donc l'endpoint, la méthode et les params (query + WhereCondition),
     * mais pas le body d'écriture.
     *
     * @return array<int,array{method:string,endpoint:string,query:array<string,mixed>,lastCall:string}>
     */
    private function history(?int $accountId, ?string $folderId): array
    {
        $logs = RequestLog::query()
            ->where('occurred_at', '>=', now()->subDays(self::HISTORY_DAYS))
            ->whereNotNull('ebp_endpoint')
            ->when($accountId, fn ($q) => $q->where('ebp_account_id', $accountId))
            ->orderByDesc('occurred_at')
            ->get(['method', 'ebp_endpoint', 'params', 'occurred_at']);

        $seen = [];
        $out = [];

        foreach ($logs as $log) {
            $endpoint = $folderId ? $this->normalizeFolder($log->ebp_endpoint, $folderId) : $log->ebp_endpoint;
            $method = $log->method ?? 'GET';
            $key = $method.' '.$endpoint;

            // Premier vu = le plus récent (tri desc) : on garde ses params.
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $out[] = [
                'method' => $method,
                'endpoint' => $endpoint,
                'query' => is_array($log->params) ? $log->params : [],
                'lastCall' => $log->occurred_at?->format('d/m/Y H:i') ?? '',
            ];
        }

        return $out;
    }

    /**
     * Remplace l'identifiant de dossier d'un chemin concret par celui du dossier courant.
     * Ne touche qu'au segment qui suit « Folders/ » ; les chemins sans ce segment sont inchangés.
     */
    private function normalizeFolder(string $endpoint, string $folderId): string
    {
        return preg_replace('#(Folders/)[^/]+#', '${1}'.$folderId, $endpoint, 1) ?? $endpoint;
    }

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
            // Endpoint brut : désactive les réécritures de compatibilité EBP (orthographe, etc.).
            'raw' => 'nullable|boolean',
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

        $typed = ltrim(trim($data['endpoint']), '/');
        $query = $this->normalizeQuery($data['query'] ?? []);
        $body = $this->decodeBody($method, $data['body'] ?? null);

        // Réécritures de compatibilité EBP (ex. ItemsExtendedQuery -> ItemsExtentedQuery),
        // appliquées comme pour une vraie app, sauf si l'utilisateur demande l'endpoint brut.
        $raw = (bool) ($data['raw'] ?? false);
        $endpoint = $raw ? $typed : ltrim(EbpPath::applyRewrites($typed), '/');
        $rewritten = $endpoint !== $typed;

        // URL finale réellement appelée (host EBP du compte + endpoint effectif), pour affichage.
        $finalUrl = rtrim($account->apiBaseUrl(), '/').'/'.$endpoint;

        $meta = [
            'url' => $finalUrl,
            'method' => $method,
            'endpoint' => $endpoint,
            'rewritten' => $rewritten,
            'typedEndpoint' => $typed,
        ];

        $startedAt = microtime(true);

        try {
            $result = $client->send($account, $folder, $method, $endpoint, $query, $body);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            return response()->json($meta + [
                'ok' => true,
                'status' => $result['status'],
                'durationMs' => $durationMs,
                'body' => $this->prettyBody($result['raw']),
            ]);
        } catch (EbpApiException $e) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            // Erreur EBP (4xx/5xx) : on renvoie quand même le corps, c'est le cœur du débogage.
            return response()->json($meta + [
                'ok' => false,
                'status' => $e->status,
                'durationMs' => $durationMs,
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
