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
