<?php

namespace App\Console\Commands;

use App\Mail\LogsPurgeFailedMail;
use App\Models\GatewayJob;
use App\Models\RequestLog;
use App\Services\Alerts\AlertService;
use App\Services\Logging\RequestLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Purge les logs (métas + corps fichiers) et les résultats de jobs au-delà de la rétention (360 j).
 * Planifiée quotidiennement (voir routes/console.php).
 */
class PurgeLogs extends Command
{
    protected $signature = 'ebp:logs:purge';

    protected $description = 'Purge les logs et résultats de jobs au-delà de la rétention configurée';

    public function handle(AlertService $alerts): int
    {
        $logsDays = (int) config('ebp.retention.logs_days');
        $jobDays = (int) config('ebp.retention.job_results_days');

        try {
            $logs = $this->purgeRequestLogs($logsDays);
            $jobs = $this->purgeJobResults($jobDays);

            $this->info("Purge terminée : {$logs} log(s) > {$logsDays} j, {$jobs} job(s) > {$jobDays} j.");
        } catch (\Throwable $e) {
            $alerts->send(
                LogsPurgeFailedMail::ALERT_TYPE,
                new LogsPurgeFailedMail('purge', $e->getMessage()),
            );

            $this->error('Purge échouée : '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function purgeRequestLogs(int $days): int
    {
        $cutoff = now()->subDays($days);
        $disk = Storage::disk(RequestLogger::DISK);
        $count = 0;

        RequestLog::where('occurred_at', '<', $cutoff)
            ->chunkById(500, function ($logs) use ($disk, &$count) {
                foreach ($logs as $log) {
                    if ($log->response_body_path) {
                        $disk->delete($log->response_body_path);
                    }
                    $log->delete();
                    $count++;
                }
            });

        return $count;
    }

    protected function purgeJobResults(int $days): int
    {
        $cutoff = now()->subDays($days);
        $disk = Storage::disk(RequestLogger::DISK);
        $count = 0;

        GatewayJob::whereNotNull('finished_at')
            ->where('finished_at', '<', $cutoff)
            ->chunkById(500, function ($jobs) use ($disk, &$count) {
                foreach ($jobs as $job) {
                    if ($job->result_path) {
                        $disk->delete($job->result_path);
                    }
                    $job->delete();
                    $count++;
                }
            });

        return $count;
    }
}
