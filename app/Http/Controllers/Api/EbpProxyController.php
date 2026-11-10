<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppToken;
use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Services\Ebp\EbpClient;
use App\Services\Ebp\EbpPath;
use App\Services\Ebp\GatewayCache;
use App\Services\Jobs\GatewayJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Proxy transparent vers l'API EBP (tous modules, tous verbes).
 *
 * Toute requête dont le chemin commence par un préfixe EBP autorisé
 * (config ebp.gateway.proxy.allowed_prefixes) est relayée telle quelle vers EBP.
 * La gateway se contente d'écouter ce qui entre et ce qui sort : elle authentifie
 * (app.token), régule le quota EBP, journalise, met en cache les lectures, et bascule
 * les lectures en job sous charge. Elle n'ajoute aucun vocabulaire : on reste une passerelle.
 *
 * FolderId implicite : les chemins EBP qui vivent dans un dossier s'écrivent
 * .../api/v1/Folders/{FolderId}/... ; la gateway insère le dossier résolu par le jeton
 * (ou ?folderId=) quand l'app ne l'a pas mis dans l'URL.
 */
class EbpProxyController extends Controller
{
    public function __construct(
        protected EbpClient $client,
        protected GatewayJobService $jobs,
        protected GatewayCache $cache,
    ) {}

    /** Point d'entrée unique : attrape tout chemin et le relaie vers EBP. */
    public function handle(Request $request, string $path): JsonResponse
    {
        $prefix = EbpPath::matchPrefix($path);

        if ($prefix === null) {
            abort(HttpResponse::HTTP_NOT_FOUND, 'Chemin hors du périmètre EBP autorisé.');
        }

        $account = $request->attributes->get('ebp_account');
        $folder = $request->attributes->get('ebp_folder');

        // Dossier : le middleware app.token a déjà renvoyé 400 (error: folder_required) si
        // l'endpoint EXIGE un dossier et qu'aucun n'est résolu. Ici $folder peut rester null
        // UNIQUEMENT pour les endpoints proxy sans dossier (racine du module, .../Folders).

        $method = $request->method();
        $endpoint = $this->withFolder($path, $prefix, $folder);
        $query = $this->forwardableQuery($request);
        $isRead = $method === 'GET';

        // 1. Cache (lecture seule, compte l'autorise, et non contourné).
        $ttl = ($isRead && $account->cacheEnabled()) ? $account->cacheTtl() : 0;
        $bypass = $this->cacheBypassed($request);
        $cacheKey = $this->cache->key($account, $folder, $endpoint, $query);

        if ($ttl > 0 && ! $bypass) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return response()->json($cached + ['cached' => true]);
            }
        }

        // 2. Bascule en job (lecture seule) si trop de requêtes en attente : 202 + jobId.
        if ($isRead && $this->jobs->shouldQueue()) {
            /** @var AppToken $token */
            $token = $request->attributes->get('app_token');
            $job = $this->jobs->dispatchRead($token, $account, $folder, ['endpoint' => $endpoint, 'query' => $query]);

            return response()->json([
                'jobId' => $job->public_id,
                'status' => $job->status,
                'pollIntervalHint' => (int) config('ebp.gateway.poll_interval_hint'),
            ], HttpResponse::HTTP_ACCEPTED);
        }

        // 3. Relai vers EBP (lecture ou écriture), via quota + logs.
        $body = $isRead ? null : $request->json()->all();
        $result = $this->client->send($account, $folder, $method, $endpoint, $query, $body);

        $payload = [
            'data' => $result['data'],
            'paging' => $result['paging'],
            // Dossier réellement ciblé : celui écrit dans l'URL par l'app, sinon celui du jeton.
            'folder' => EbpPath::needsFolder($path, $prefix) ? $this->targetFolderId($endpoint, $prefix, $folder) : null,
        ];

        if ($isRead) {
            if ($ttl > 0 && ! $bypass) {
                $this->cache->put($cacheKey, $payload, $ttl);
            }
        } else {
            // Une écriture peut avoir changé les données du dossier : on vide son cache de lecture.
            $this->cache->flushFolder($account, $folder);
        }

        return response()->json($payload, $result['status'] ?? HttpResponse::HTTP_OK);
    }

    /**
     * Insère le FolderId dans le chemin si l'endpoint vit dans un dossier et que l'app ne l'a pas mis.
     *
     * - "<prefix>"                         -> inchangé (ex. .../Folders : liste des dossiers)
     * - "<prefix>/Folders/123/Clients"     -> inchangé (FolderId déjà présent)
     * - "<prefix>/Clients"                 -> "<prefix>/Folders/{folderId}/Clients"
     */
    protected function withFolder(string $path, string $prefix, ?EbpFolder $folder): string
    {
        $rest = ltrim(substr($path, strlen($prefix)), '/');

        // Racine du module (liste des dossiers, ou appel sans ressource) : pas de FolderId.
        // (le dossier peut légitimement être null dans ce cas.)
        if ($rest === '' || $rest === 'Folders' || str_starts_with($rest, 'Folders/')) {
            return $path;
        }

        // Ici le garde-fou de handle() a déjà garanti un dossier non-null.
        return $prefix.'/Folders/'.$folder->folder_id.'/'.$rest;
    }

    /**
     * FolderId réellement ciblé par l'endpoint final (après injection éventuelle).
     * Lu dans le chemin `.../Folders/{id}/...` ; à défaut, le dossier du jeton.
     */
    protected function targetFolderId(string $endpoint, string $prefix, EbpFolder $folder): string
    {
        if (preg_match('#(?:^|/)Folders/([^/]+)/#', $endpoint, $m)) {
            return $m[1];
        }

        return $folder->folder_id;
    }

    /** Query string à relayer, sans les paramètres de contrôle de la gateway. */
    protected function forwardableQuery(Request $request): array
    {
        return $request->except(['folderId', 'noCache']);
    }

    /** Cache contourné par ?noCache=1 ou l'en-tête Cache-Control: no-cache. */
    protected function cacheBypassed(Request $request): bool
    {
        return $request->boolean('noCache')
            || str_contains(strtolower($request->header('Cache-Control', '')), 'no-cache');
    }

}
