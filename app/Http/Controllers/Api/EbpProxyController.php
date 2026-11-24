<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppToken;
use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Services\Ebp\EbpClient;
use App\Services\Ebp\EbpPath;
use App\Services\Ebp\GatewayCache;
use App\Services\Ebp\MultipartBody;
use App\Services\Ebp\WhereConditionTranslator;
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
        protected WhereConditionTranslator $whereTranslator,
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
        // Réécritures de compatibilité (orthographe fautive côté EBP) avant tout le reste,
        // pour que cache, journalisation et relai partagent le chemin réellement appelé.
        $endpoint = EbpPath::applyRewrites($this->withFolder($path, $prefix, $folder));
        $query = $this->translateWhereCondition($this->forwardableQuery($request));
        // Modules « plats » qui attendent le FolderId en query (Import/Export) : on l'injecte si
        // l'app ne l'a pas mis. Les modules « Folders » (gescom…) le portent déjà dans l'URL.
        $query = $this->injectFolderInQuery($query, $prefix, $folder);
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
        // Le corps est reconstruit selon le type réellement reçu : multipart (upload de fichier,
        // ex. Import/CreateImport) relayé en multipart, sinon corps JSON. Le FolderId peut être
        // injecté dans le corps pour les écritures qui l'attendent là (Import/Export) ; on ne
        // l'ajoute pas quand le chemin porte déjà un dossier.
        $body = $isRead ? null : $this->outgoingBody($request, $folder, str_contains($endpoint, '/Folders/'));
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
     * Construit le corps à relayer à EBP pour une écriture, selon le type reçu de l'app.
     *
     * - multipart/form-data (au moins un fichier) -> {@see MultipartBody} : fichiers + champs texte,
     *   relayés en multipart (cas Import/CreateImport, qu'EBP refuse en application/json).
     * - sinon -> corps JSON (array) comme avant.
     *
     * Dans les deux cas, le FolderId est injecté quand l'endpoint l'attend dans le corps.
     */
    protected function outgoingBody(Request $request, ?EbpFolder $folder, bool $folderInPath): mixed
    {
        if ($request->allFiles() !== []) {
            $multipart = new MultipartBody(
                files: $request->allFiles(),
                // post() = champs du corps (form-urlencoded / multipart), hors fichiers et hors query.
                fields: $request->post(),
            );

            return $this->injectFolderInBody($multipart, $folder, $folderInPath);
        }

        $body = $request->json()->all();

        return $this->injectFolderInBody($body, $folder, $folderInPath);
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
        // Seuls les modules « Folders » (gescom, batiment, maintenance) portent le FolderId
        // dans l'URL. Pour les autres, on ne touche pas au chemin.
        if (! EbpPath::needsFolder($path, $prefix)) {
            return $path;
        }

        $rest = ltrim(substr($path, strlen($prefix)), '/');

        // Déjà écrit par l'app (…/Folders/{id}/…) : on le laisse tel quel.
        if (str_starts_with($rest, 'Folders/')) {
            return $path;
        }

        // Ici le garde-fou de handle() a déjà garanti un dossier non-null.
        return $prefix.'/Folders/'.$folder->folder_id.'/'.$rest;
    }

    /**
     * Injecte le FolderId dans le corps d'une écriture quand l'app ne l'a pas fourni.
     *
     * Certains endpoints EBP (Import/ModelSettings, Export/CreateExport) attendent `folderId`
     * dans le corps JSON (pas dans l'URL). On le renseigne depuis le dossier résolu si la clé
     * est absente ou vide ; une valeur déjà fournie par l'app est respectée. Sans dossier résolu
     * ou si le corps n'est pas un objet, on ne touche à rien.
     *
     * @param  mixed  $body
     * @param  bool  $folderInPath  Le chemin porte déjà un /Folders/{id}/ — on n'ajoute alors pas la clé.
     * @return mixed
     */
    protected function injectFolderInBody(mixed $body, ?EbpFolder $folder, bool $folderInPath): mixed
    {
        if (! $folder) {
            return $body;
        }

        // Multipart : on complète les champs texte (pas les fichiers), même règle que pour le JSON.
        if ($body instanceof MultipartBody) {
            $body->fields = $this->withFolderId($body->fields, $folder, $folderInPath);

            return $body;
        }

        if (! is_array($body) || array_is_list($body)) {
            return $body;
        }

        return $this->withFolderId($body, $folder, $folderInPath);
    }

    /**
     * Complète un tableau de champs avec le FolderId attendu par EBP :
     * respecte une valeur fournie par l'app (ne remplace que si vide) et n'ajoute la clé
     * absente que si le dossier n'est pas déjà porté par l'URL (cas Import/Export).
     *
     * @param  array<string,mixed>  $fields
     * @return array<string,mixed>
     */
    protected function withFolderId(array $fields, EbpFolder $folder, bool $folderInPath): array
    {
        foreach (['folderId', 'FolderId'] as $key) {
            if (array_key_exists($key, $fields)) {
                if (blank($fields[$key])) {
                    $fields[$key] = $folder->folder_id;
                }

                return $fields;
            }
        }

        if (! $folderInPath) {
            $fields['folderId'] = $folder->folder_id;
        }

        return $fields;
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

    /**
     * Query string à relayer, sans les paramètres de contrôle de la gateway.
     *
     * On part de la SEULE query string ($request->query()), jamais du corps : sinon, sur une
     * requête multipart, les champs POST et surtout les UploadedFile se retrouveraient dans la
     * query (et dans les logs, où ils cassent l'encodage JSON). Le corps est géré à part.
     */
    protected function forwardableQuery(Request $request): array
    {
        return collect($request->query())->except(['folderId', 'noCache'])->all();
    }

    /**
     * Injecte le FolderId dans la query string pour les modules « plats » qui l'attendent là
     * (Import/Export), quand l'app ne l'a pas fourni. Les modules « Folders » portent le dossier
     * dans l'URL : on ne touche alors pas à la query. Une valeur fournie par l'app est respectée.
     *
     * @param  array<string,mixed>  $query
     * @return array<string,mixed>
     */
    protected function injectFolderInQuery(array $query, string $prefix, ?EbpFolder $folder): array
    {
        if (! $folder || EbpPath::usesFolderUrl($prefix)) {
            return $query;
        }

        foreach (['FolderId', 'folderId'] as $key) {
            if (array_key_exists($key, $query) && filled($query[$key])) {
                return $query;
            }
        }

        // Casse attendue par l'API Import/Export d'EBP sur ses paramètres de query.
        $query['FolderId'] = $folder->folder_id;

        return $query;
    }

    /**
     * Traduit la valeur `WhereCondition` (OData → YAML EBP) avant tout le reste, pour que cache,
     * journalisation et relai partagent la valeur réellement envoyée à EBP. Le YAML déjà formaté
     * est relayé tel quel ; une valeur OData non reconnue est laissée inchangée (cf. le service).
     *
     * @param  array<string,mixed>  $query
     * @return array<string,mixed>
     */
    protected function translateWhereCondition(array $query): array
    {
        if (isset($query['WhereCondition']) && is_string($query['WhereCondition'])) {
            $query['WhereCondition'] = $this->whereTranslator->translate($query['WhereCondition']);
        }

        return $query;
    }

    /** Cache contourné par ?noCache=1 ou l'en-tête Cache-Control: no-cache. */
    protected function cacheBypassed(Request $request): bool
    {
        return $request->boolean('noCache')
            || str_contains(strtolower($request->header('Cache-Control', '')), 'no-cache');
    }

}
