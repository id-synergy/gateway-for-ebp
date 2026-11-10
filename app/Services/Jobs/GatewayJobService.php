<?php

namespace App\Services\Jobs;

use App\Jobs\BatchSubReadJob;
use App\Jobs\ExecuteEbpReadJob;
use App\Models\AppToken;
use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Models\GatewayJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

/**
 * Crée et pilote les jobs de la gateway.
 *
 * Décision direct / file : au-delà d'un seuil configurable de jobs en attente,
 * une lecture est basculée en job (202 + jobId + polling). Sinon elle reste synchrone.
 */
class GatewayJobService
{
    /** Vrai s'il faut basculer en file (trop de jobs en attente). */
    public function shouldQueue(): bool
    {
        $threshold = (int) config('ebp.gateway.queue_switch_threshold');

        return $this->pendingCount() >= $threshold;
    }

    /** Nombre de jobs gateway non terminés. */
    public function pendingCount(): int
    {
        return GatewayJob::whereIn('status', ['pending', 'running'])->count();
    }

    /**
     * Crée un job de lecture et le met en file. Retourne le GatewayJob (avec public_id).
     *
     * @param  array<string,mixed>  $payload  { endpoint, query }
     */
    public function dispatchRead(AppToken $token, EbpAccount $account, ?EbpFolder $folder, array $payload): GatewayJob
    {
        $job = GatewayJob::create([
            'public_id' => Str::uuid(),
            'type' => 'simple',
            'app_token_id' => $token->id,
            'ebp_account_id' => $account->id,
            'ebp_folder_id' => $folder?->id,
            'status' => 'pending',
            'progress_total' => 1,
            'progress_done' => 0,
            'result_inline' => ['_payload' => $payload],
        ]);

        ExecuteEbpReadJob::dispatch($job->id, $payload);

        return $job;
    }

    /**
     * Crée un batch de lectures : un sous-job par requête, résultats regroupés par clé.
     * Le parent passe à done (ou partial si au moins un sous-job échoue) quand tout est fini.
     *
     * @param  list<array{key: string, endpoint: string, query?: array<string,mixed>}>  $requests
     */
    public function dispatchBatch(AppToken $token, EbpAccount $account, EbpFolder $folder, array $requests, string $type = 'batch'): GatewayJob
    {
        $parent = GatewayJob::create([
            'public_id' => Str::uuid(),
            'type' => $type,
            'app_token_id' => $token->id,
            'ebp_account_id' => $account->id,
            'ebp_folder_id' => $folder->id,
            'status' => 'pending',
            'progress_total' => count($requests),
            'progress_done' => 0,
            'result_inline' => [],
        ]);

        $subJobs = array_map(
            fn (array $r) => new BatchSubReadJob($parent->id, $r['key'], ['endpoint' => $r['endpoint'], 'query' => $r['query'] ?? []]),
            $requests,
        );

        $parentId = $parent->id;

        // Marque "running" AVANT le dispatch : en connexion sync, les sous-jobs s'exécutent
        // pendant dispatch() et peuvent finaliser le parent — ne pas l'écraser après coup.
        $parent->update(['status' => 'running']);

        $batch = Bus::batch($subJobs)
            ->then(fn () => $this->finalize($parentId))
            ->catch(fn () => $this->finalize($parentId))
            ->finally(fn () => $this->finalize($parentId))
            ->name("gateway-{$type}:{$parent->public_id}")
            ->dispatch();

        // N'enregistre l'id du batch que si le parent n'a pas déjà été clôturé (sync).
        $parent->refresh();
        if (! in_array($parent->status, ['done', 'partial', 'failed'], true)) {
            $parent->update(['laravel_batch_id' => $batch->id]);
        }

        return $parent->fresh();
    }

    /** Clôture le job parent : done si aucun sous-job en erreur, sinon partial. */
    public function finalize(int $parentId): void
    {
        $parent = GatewayJob::find($parentId);

        if (! $parent || in_array($parent->status, ['done', 'partial', 'failed'], true)) {
            return;
        }

        $hasErrors = ! empty($parent->sub_errors);

        $parent->update([
            'status' => $hasErrors ? 'partial' : 'done',
            'finished_at' => now(),
        ]);

        self::offloadResultIfLarge($parent);
    }

    /** Seuil au-delà duquel un résultat volumineux est écrit en fichier plutôt qu'en base. */
    public const RESULT_INLINE_MAX_BYTES = 65536; // 64 Ko

    /**
     * Déplace un result_inline volumineux vers un fichier (result_path) pour éviter
     * de stocker de gros blobs JSON en base. Petit résultat → reste inline.
     */
    public static function offloadResultIfLarge(GatewayJob $job): void
    {
        if (empty($job->result_inline)) {
            return;
        }

        $json = json_encode($job->result_inline);

        if (strlen($json) <= self::RESULT_INLINE_MAX_BYTES) {
            return;
        }

        $path = 'ebp-logs/job-results/'.now()->format('Y/m/d').'/'.$job->public_id.'.json';
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, $json);

        $job->update(['result_path' => $path, 'result_inline' => null]);
    }
}
