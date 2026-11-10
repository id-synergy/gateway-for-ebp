<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppToken;
use App\Models\GatewayJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suivi des jobs par les apps : statut/progression et récupération du résultat.
 * Une app ne voit que ses propres jobs (ceux de son jeton).
 */
class JobsController extends Controller
{
    /** Statuts considérés « en cours » (ni terminés, ni en échec). */
    private const IN_PROGRESS = ['pending', 'running'];

    /**
     * Liste les jobs du jeton appelant (les plus récents d'abord).
     *
     * Filtres : ?status=pending|running|done|partial|failed, ou ?status=in_progress
     * (raccourci pending+running = jobs en cours). Pagination : ?limit (défaut 50, max 100), ?offset.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var AppToken $token */
        $token = $request->attributes->get('app_token');

        $limit = min(100, max(1, (int) $request->query('limit', 50)));
        $offset = max(0, (int) $request->query('offset', 0));

        $query = GatewayJob::where('app_token_id', $token->id)->latest('id');

        if ($status = $request->query('status')) {
            $query->whereIn('status', $status === 'in_progress' ? self::IN_PROGRESS : [$status]);
        }

        $total = (clone $query)->count();
        $jobs = $query->offset($offset)->limit($limit)->get();

        return response()->json([
            'data' => $jobs->map(fn (GatewayJob $job) => [
                'jobId' => $job->public_id,
                'type' => $job->type,
                'status' => $job->status,
                'progress' => ['done' => $job->progress_done, 'total' => $job->progress_total],
                'createdAt' => optional($job->created_at)->toIso8601String(),
                'finishedAt' => optional($job->finished_at)->toIso8601String(),
            ]),
            'paging' => ['offset' => $offset, 'limit' => $limit, 'total' => $total],
        ]);
    }

    public function status(Request $request, string $jobId): JsonResponse
    {
        $job = $this->findOwnedJob($request, $jobId);

        return response()->json([
            'jobId' => $job->public_id,
            'type' => $job->type,
            'status' => $job->status,
            'progress' => ['done' => $job->progress_done, 'total' => $job->progress_total],
            'pollIntervalHint' => (int) config('ebp.gateway.poll_interval_hint'),
            'finishedAt' => optional($job->finished_at)->toIso8601String(),
        ]);
    }

    public function result(Request $request, string $jobId): JsonResponse
    {
        $job = $this->findOwnedJob($request, $jobId);

        if (! in_array($job->status, ['done', 'partial', 'failed'], true)) {
            return response()->json(['message' => 'Job non terminé.', 'status' => $job->status], 409);
        }

        return response()->json([
            'jobId' => $job->public_id,
            'status' => $job->status,
            'result' => $this->resultOf($job),
            'errors' => $job->sub_errors,
        ]);
    }

    protected function findOwnedJob(Request $request, string $jobId): GatewayJob
    {
        /** @var AppToken $token */
        $token = $request->attributes->get('app_token');

        return GatewayJob::where('public_id', $jobId)
            ->where('app_token_id', $token->id)
            ->firstOrFail();
    }

    /** Résultat du job : inline, ou lu depuis le fichier pour les gros résultats. */
    protected function resultOf(GatewayJob $job): mixed
    {
        if ($job->result_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($job->result_path)) {
            return json_decode(\Illuminate\Support\Facades\Storage::disk('local')->get($job->result_path), true);
        }

        return $job->result_inline;
    }
}
