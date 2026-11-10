<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppToken;
use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Services\Ebp\EbpClient;
use App\Services\Jobs\GatewayJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Requêtes multiples :
 *  - POST /batch   : un lot de requêtes indépendantes -> un sous-job chacune, résultats regroupés.
 *  - POST /compose : une requête "liste" (étape 1) qui engendre un sous-job par élément (étape 2),
 *                    ex. CA par client = liste clients puis factures par client.
 * Les deux renvoient 202 + jobId ; l'app récupère le résultat par polling.
 */
class ComposeController extends Controller
{
    public function __construct(
        protected EbpClient $client,
        protected GatewayJobService $jobs,
    ) {}

    /** POST /batch  body: { requests: [{ key, endpoint, query? }, ...] } */
    public function batch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'requests' => 'required|array|min:1|max:100',
            'requests.*.key' => 'required|string',
            'requests.*.endpoint' => 'required|string',
            'requests.*.query' => 'array',
        ]);

        $job = $this->jobs->dispatchBatch(
            $this->token($request), $this->account($request), $this->folder($request),
            $validated['requests'], 'batch'
        );

        return $this->accepted($job);
    }

    /**
     * POST /compose
     * body: {
     *   list: { endpoint, query? },        // étape 1 (ex. liste clients)
     *   itemKey: "id",                     // champ identifiant de chaque élément
     *   perItem: { endpointTemplate, queryParam }  // étape 2 (ex. factures par client)
     * }
     * endpointTemplate peut contenir {item} ; queryParam reçoit l'id si fourni.
     */
    public function compose(Request $request): JsonResponse
    {
        $v = $request->validate([
            'list.endpoint' => 'required|string',
            'list.query' => 'array',
            'itemKey' => 'required|string',
            'perItem.endpointTemplate' => 'required|string',
            'perItem.queryParam' => 'nullable|string',
        ]);

        $account = $this->account($request);
        $folder = $this->folder($request);

        // Étape 1 : récupérer la liste (appel synchrone, via le client qui passe par le quota).
        $list = $this->client->get($account, $folder, $v['list']['endpoint'], $v['list']['query'] ?? []);
        $items = is_array($list['data']) ? $list['data'] : [];

        // Étape 2 : une sous-requête par élément.
        $requests = [];
        foreach ($items as $item) {
            $id = is_array($item) ? ($item[$v['itemKey']] ?? null) : $item;
            if ($id === null) {
                continue;
            }
            $endpoint = str_replace('{item}', (string) $id, $v['perItem']['endpointTemplate']);
            $query = [];
            if (! empty($v['perItem']['queryParam'])) {
                $query[$v['perItem']['queryParam']] = $id;
            }
            $requests[] = ['key' => (string) $id, 'endpoint' => $endpoint, 'query' => $query];
        }

        if (empty($requests)) {
            return response()->json(['message' => 'Aucun élément à traiter.'], 422);
        }

        $job = $this->jobs->dispatchBatch($this->token($request), $account, $folder, $requests, 'compose');

        return $this->accepted($job);
    }

    protected function accepted($job): JsonResponse
    {
        return response()->json([
            'jobId' => $job->public_id,
            'status' => $job->status,
            'total' => $job->progress_total,
            'pollIntervalHint' => (int) config('ebp.gateway.poll_interval_hint'),
        ], 202);
    }

    protected function token(Request $request): AppToken
    {
        return $request->attributes->get('app_token');
    }

    protected function account(Request $request): EbpAccount
    {
        return $request->attributes->get('ebp_account');
    }

    protected function folder(Request $request): EbpFolder
    {
        return $request->attributes->get('ebp_folder');
    }
}
