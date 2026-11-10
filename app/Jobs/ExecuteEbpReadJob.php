<?php

namespace App\Jobs;

use App\Models\GatewayJob;
use App\Services\Ebp\EbpClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Exécute une lecture EBP en arrière-plan pour un GatewayJob, au rythme du quota.
 * Stocke le résultat (inline) et met le job à jour (done / failed).
 */
class ExecuteEbpReadJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string,mixed>  $payload  { endpoint, query }
     */
    public function __construct(
        public int $gatewayJobId,
        public array $payload,
    ) {}

    public function handle(EbpClient $client): void
    {
        $job = GatewayJob::find($this->gatewayJobId);

        if (! $job) {
            return;
        }

        $job->update(['status' => 'running']);

        try {
            $result = $client->get(
                $job->account,
                $job->folder,
                $this->payload['endpoint'],
                $this->payload['query'] ?? [],
            );

            $job->update([
                'status' => 'done',
                'progress_done' => $job->progress_total,
                'result_inline' => ['data' => $result['data'], 'paging' => $result['paging']],
                'finished_at' => now(),
            ]);

            \App\Services\Jobs\GatewayJobService::offloadResultIfLarge($job->fresh());
        } catch (\Throwable $e) {
            $job->update([
                'status' => 'failed',
                'sub_errors' => [['message' => $e->getMessage()]],
                'finished_at' => now(),
            ]);
        }
    }
}
