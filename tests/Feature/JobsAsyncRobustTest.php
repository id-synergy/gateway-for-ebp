<?php

namespace Tests\Feature;

use App\Jobs\ExecuteEbpReadJob;
use App\Models\AppToken;
use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Models\GatewayJob;
use App\Services\Apps\AppTokenService;
use App\Services\Ebp\EbpTokenService;
use App\Services\Jobs\GatewayJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Durcissement de la partie asynchrone (jobs, batch, compose) : cycle de statut,
 * bascule sous charge, résultat partiel, dépendances, robustesse. Complète
 * GatewayJobsTest et ComposeBatchTest sans les dupliquer.
 */
class JobsAsyncRobustTest extends TestCase
{
    use RefreshDatabase;

    private string $plain;

    private AppToken $token;

    private EbpAccount $account;

    private EbpFolder $folder;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
        config(['ebp.quota.per_second' => 1000]);

        $this->account = EbpAccount::factory()->create(['is_active' => true]);
        Http::fake([config('ebp.token_url') => Http::response(['access_token' => 'A1', 'refresh_token' => 'R1', 'expires_in' => 3600])]);
        app(EbpTokenService::class)->exchangeCode($this->account, 'CODE');
        $this->account = $this->account->fresh();
        $this->folder = EbpFolder::factory()->create(['ebp_account_id' => $this->account->id, 'folder_id' => '3691']);
        [$this->token, $this->plain] = app(AppTokenService::class)->create('App', $this->account, $this->folder);
    }

    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->plain}"];
    }

    private function service(): GatewayJobService
    {
        return app(GatewayJobService::class);
    }

    private function makePendingJob(string $status): GatewayJob
    {
        return GatewayJob::create([
            'public_id' => Str::uuid(),
            'type' => 'simple',
            'app_token_id' => $this->token->id,
            'ebp_account_id' => $this->account->id,
            'ebp_folder_id' => $this->folder->id,
            'status' => $status,
            'progress_total' => 1,
            'progress_done' => 0,
        ]);
    }

    // --- Cycle de statut pas à pas ---

    public function test_cycle_de_statut_pending_running_done(): void
    {
        Queue::fake(); // le job ne s'exécute pas tout de suite

        $job = $this->service()->dispatchRead($this->token, $this->account, $this->folder, [
            'endpoint' => 'gescom/api/v1/Folders/3691/Clients', 'query' => [],
        ]);

        // À la création : pending, progression 0/1.
        $this->assertSame('pending', $job->status);
        $this->assertSame(0, $job->progress_done);
        $this->assertSame(1, $job->progress_total);

        // Exécution du job -> done, progression complète.
        Http::fake(['*/Clients*' => Http::response(['results' => [['id' => 'C1']]])]);
        (new ExecuteEbpReadJob($job->id, ['endpoint' => 'gescom/api/v1/Folders/3691/Clients', 'query' => []]))
            ->handle(app(\App\Services\Ebp\EbpClient::class));

        $job->refresh();
        $this->assertSame('done', $job->status);
        $this->assertSame($job->progress_total, $job->progress_done);
        $this->assertNotNull($job->finished_at);
    }

    public function test_un_job_en_echec_passe_failed_et_le_statut_le_dit(): void
    {
        Http::fake(['*/Clients*' => Http::response('boom', 500)]);

        $job = $this->service()->dispatchRead($this->token, $this->account, $this->folder, [
            'endpoint' => 'gescom/api/v1/Folders/3691/Clients', 'query' => [],
        ]);
        $job->refresh();

        $this->assertSame('failed', $job->status);
        $this->getJson("/api/v1/jobs/{$job->public_id}", $this->auth())
            ->assertOk()
            ->assertJsonPath('status', 'failed');
    }

    // --- Bascule sous charge ---

    public function test_la_bascule_respecte_le_seuil_configure(): void
    {
        config(['ebp.gateway.queue_switch_threshold' => 3]);
        Queue::fake();

        // 2 jobs en attente < seuil 3 -> encore synchrone.
        $this->makePendingJob('pending');
        $this->makePendingJob('pending');
        $this->assertFalse($this->service()->shouldQueue());

        // 3e job en attente -> seuil atteint -> bascule.
        $this->makePendingJob('running');
        $this->assertTrue($this->service()->shouldQueue());
    }

    public function test_le_202_porte_jobid_statut_et_hint_de_polling(): void
    {
        config(['ebp.gateway.queue_switch_threshold' => 1]);
        Queue::fake();
        $this->makePendingJob('pending');

        $this->getJson('/gescom/api/v1/Clients', $this->auth())
            ->assertStatus(202)
            ->assertJsonStructure(['jobId', 'status', 'pollIntervalHint'])
            ->assertJsonPath('status', 'pending');

        Queue::assertPushed(ExecuteEbpReadJob::class);
    }

    // --- Batch : résultat partiel récupéré (réussis + erreurs) ---

    public function test_batch_partiel_rend_les_reussis_et_les_erreurs(): void
    {
        Http::fake([
            '*/OK*' => Http::response(['results' => [['v' => 'ok']]]),
            '*/KO*' => Http::response('fail', 500),
        ]);

        $res = $this->postJson('/api/v1/batch', [
            'requests' => [
                ['key' => 'ok', 'endpoint' => 'gescom/api/v1/Folders/3691/OK'],
                ['key' => 'ko', 'endpoint' => 'gescom/api/v1/Folders/3691/KO'],
            ],
        ], $this->auth());

        $jobId = $res->json('jobId');

        // Statut partial.
        $this->getJson("/api/v1/jobs/{$jobId}", $this->auth())->assertJsonPath('status', 'partial');

        // Résultat : le réussi est là, l'erreur aussi.
        $result = $this->getJson("/api/v1/jobs/{$jobId}/result", $this->auth())->assertOk();
        $result->assertJsonPath('status', 'partial');
        $result->assertJsonPath('result.ok.0.v', 'ok');
        $result->assertJsonPath('errors.0.key', 'ko');
    }

    public function test_la_progression_avance_avec_les_sous_jobs(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        $res = $this->postJson('/api/v1/batch', [
            'requests' => [
                ['key' => 'a', 'endpoint' => 'gescom/api/v1/Folders/3691/A'],
                ['key' => 'b', 'endpoint' => 'gescom/api/v1/Folders/3691/B'],
                ['key' => 'c', 'endpoint' => 'gescom/api/v1/Folders/3691/C'],
            ],
        ], $this->auth());

        $this->getJson("/api/v1/jobs/{$res->json('jobId')}", $this->auth())
            ->assertJsonPath('progress.total', 3)
            ->assertJsonPath('progress.done', 3); // sync : tout exécuté
    }

    // --- Compose : dépendances (étape 1 nourrit l'étape 2) ---

    public function test_compose_chaine_la_liste_puis_un_sous_job_par_element(): void
    {
        Http::fake([
            // Étape 1 : liste de 2 clients.
            '*/Clients*' => Http::response(['results' => [['id' => 'C1'], ['id' => 'C2']]]),
            // Étape 2 : documents par client (un appel par id).
            '*/Documents/SaleDocument*' => Http::response(['results' => [['n' => 1]]]),
        ]);

        $res = $this->postJson('/api/v1/compose', [
            'list' => ['endpoint' => 'gescom/api/v1/Folders/3691/Clients'],
            'itemKey' => 'id',
            'perItem' => ['endpointTemplate' => 'gescom/api/v1/Folders/3691/Clients/{item}/Documents/SaleDocument'],
        ], $this->auth());

        $jobId = $res->json('jobId');
        // Un sous-job par élément de la liste -> progression = 2.
        $this->getJson("/api/v1/jobs/{$jobId}", $this->auth())
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('progress.total', 2);

        // Les résultats sont rangés par identifiant d'élément.
        $this->getJson("/api/v1/jobs/{$jobId}/result", $this->auth())
            ->assertOk()
            ->assertJsonPath('status', 'done');
    }

    // --- Envoi manuel en file (sans bascule de charge) ---

    public function test_batch_met_toujours_en_file_meme_sans_charge(): void
    {
        config(['ebp.gateway.queue_switch_threshold' => 1000]); // jamais atteint
        Http::fake(['*' => Http::response(['results' => []])]);

        // Même sans charge, batch renvoie 202 (c'est un envoi explicite en file).
        $this->postJson('/api/v1/batch', [
            'requests' => [['key' => 'a', 'endpoint' => 'gescom/api/v1/Folders/3691/A']],
        ], $this->auth())->assertStatus(202)->assertJsonStructure(['jobId']);
    }

    // --- Robustesse ---

    public function test_resultat_demande_sur_job_inconnu_404(): void
    {
        $this->getJson('/api/v1/jobs/'.Str::uuid().'/result', $this->auth())->assertStatus(404);
    }

    public function test_un_resultat_reste_recuperable_apres_plusieurs_lectures(): void
    {
        Http::fake(['*/Clients*' => Http::response(['results' => [['id' => 'C1']]])]);

        $job = $this->service()->dispatchRead($this->token, $this->account, $this->folder, [
            'endpoint' => 'gescom/api/v1/Folders/3691/Clients', 'query' => [],
        ]);

        // Lecture idempotente : plusieurs appels donnent le même résultat.
        for ($i = 0; $i < 3; $i++) {
            $this->getJson("/api/v1/jobs/{$job->public_id}/result", $this->auth())
                ->assertOk()
                ->assertJsonPath('status', 'done');
        }
    }
}
