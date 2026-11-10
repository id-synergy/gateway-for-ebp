<?php

namespace App\Jobs;

use App\Models\GatewayJob;
use App\Services\Ebp\EbpClient;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Sous-job d'un batch/compose : lit un endpoint EBP et range son résultat (ou son erreur)
 * dans le GatewayJob parent, sous la clé $resultKey. Met à jour la progression.
 * Le parent passe à done/partial quand tous les sous-jobs sont finis (callback du batch).
 */
class BatchSubReadJob implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * @param  array<string,mixed>  $request  { endpoint, query }
     */
    public function __construct(
        public int $gatewayJobId,
        public string $resultKey,
        public array $request,
    ) {}

    public function handle(EbpClient $client): void
    {
        $parent = GatewayJob::find($this->gatewayJobId);

        if (! $parent) {
            return;
        }

        $error = null;
        $data = null;

        try {
            $result = $client->get(
                $parent->account,
                $parent->folder,
                $this->request['endpoint'],
                $this->request['query'] ?? [],
            );
            $data = $result['data'];
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        // Écriture concurrente du résultat partiel : sérialisée par verrou sur le parent.
        $lock = Cache::lock("gateway-job:{$parent->id}", 10);
        $lock->block(10);

        try {
            $parent->refresh();

            $results = $parent->result_inline ?? [];
            $results[$this->resultKey] = $error === null ? $data : ['error' => $error];
            $parent->result_inline = $results;

            if ($error !== null) {
                $errors = $parent->sub_errors ?? [];
                $errors[] = ['key' => $this->resultKey, 'message' => $error];
                $parent->sub_errors = $errors;
            }

            $parent->progress_done = ($parent->progress_done ?? 0) + 1;
            $parent->save();

            // Dernier sous-job terminé -> clôture le parent (done/partial), indépendamment
            // des callbacks du batch (qui ne se déclenchent pas en connexion sync).
            if ($parent->progress_done >= $parent->progress_total
                && ! in_array($parent->status, ['done', 'partial', 'failed'], true)) {
                $parent->update([
                    'status' => empty($parent->sub_errors) ? 'done' : 'partial',
                    'finished_at' => now(),
                ]);
            }
        } finally {
            $lock->release();
        }
    }
}
