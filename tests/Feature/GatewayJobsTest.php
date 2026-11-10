<?php

namespace Tests\Feature;

use App\Jobs\ExecuteEbpReadJob;
use App\Models\AppToken;
use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Models\GatewayJob;
use App\Services\Apps\AppTokenService;
use App\Services\Ebp\EbpTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class GatewayJobsTest extends TestCase
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

    public function test_sous_le_seuil_reste_synchrone(): void
    {
        config(['ebp.gateway.queue_switch_threshold' => 10]);
        Http::fake(['*/Clients*' => Http::response(['results' => [['id' => 'C1']]])]);

        $this->getJson('/gescom/api/v1/Clients', $this->auth())->assertOk()->assertJsonPath('folder', '3691');
    }

    public function test_au_dela_du_seuil_bascule_en_job(): void
    {
        config(['ebp.gateway.queue_switch_threshold' => 1]);
        Queue::fake();

        // Un job en attente -> pendingCount() >= seuil (1) -> bascule.
        GatewayJob::create(['public_id' => Str::uuid(), 'type' => 'simple', 'status' => 'pending']);

        $res = $this->getJson('/gescom/api/v1/Clients', $this->auth())->assertStatus(202);
        $res->assertJsonStructure(['jobId', 'status', 'pollIntervalHint']);

        Queue::assertPushed(ExecuteEbpReadJob::class);
    }

    public function test_execution_du_job_produit_le_resultat(): void
    {
        Http::fake(['*/Folders/3691/Clients*' => Http::response(['results' => [['id' => 'C1'], ['id' => 'C2']]])]);

        $job = app(\App\Services\Jobs\GatewayJobService::class)->dispatchRead(
            $this->token, $this->account, $this->folder,
            ['endpoint' => 'gescom/api/v1/Folders/3691/Clients', 'query' => []]
        );

        (new ExecuteEbpReadJob($job->id, ['endpoint' => 'gescom/api/v1/Folders/3691/Clients', 'query' => []]))
            ->handle(app(\App\Services\Ebp\EbpClient::class));

        $job->refresh();
        $this->assertSame('done', $job->status);
        $this->assertCount(2, $job->result_inline['data']);
    }

    public function test_polling_statut_puis_resultat(): void
    {
        $job = GatewayJob::create([
            'public_id' => Str::uuid(),
            'type' => 'simple',
            'app_token_id' => $this->token->id,
            'status' => 'done',
            'progress_total' => 1,
            'progress_done' => 1,
            'result_inline' => ['data' => [['id' => 'X']]],
            'finished_at' => now(),
        ]);

        $this->getJson("/api/v1/jobs/{$job->public_id}", $this->auth())
            ->assertOk()->assertJsonPath('status', 'done');

        $this->getJson("/api/v1/jobs/{$job->public_id}/result", $this->auth())
            ->assertOk()->assertJsonPath('result.data.0.id', 'X');
    }

    public function test_resultat_409_si_job_non_termine(): void
    {
        $job = GatewayJob::create([
            'public_id' => Str::uuid(), 'type' => 'simple',
            'app_token_id' => $this->token->id, 'status' => 'running',
        ]);

        $this->getJson("/api/v1/jobs/{$job->public_id}/result", $this->auth())->assertStatus(409);
    }

    public function test_une_app_ne_voit_pas_le_job_dune_autre(): void
    {
        [$autreToken] = app(AppTokenService::class)->create('Autre', $this->account, $this->folder);
        $job = GatewayJob::create([
            'public_id' => Str::uuid(), 'type' => 'simple',
            'app_token_id' => $autreToken->id, 'status' => 'done', 'finished_at' => now(),
        ]);

        $this->getJson("/api/v1/jobs/{$job->public_id}", $this->auth())->assertStatus(404);
    }

    public function test_la_liste_des_jobs_ne_renvoie_que_ceux_du_jeton(): void
    {
        GatewayJob::create(['public_id' => Str::uuid(), 'type' => 'simple', 'app_token_id' => $this->token->id, 'status' => 'running']);
        GatewayJob::create(['public_id' => Str::uuid(), 'type' => 'simple', 'app_token_id' => $this->token->id, 'status' => 'done', 'finished_at' => now()]);
        // Job d'un autre jeton -> ne doit pas apparaître.
        [$autre] = app(AppTokenService::class)->create('Autre', $this->account, $this->folder);
        GatewayJob::create(['public_id' => Str::uuid(), 'type' => 'simple', 'app_token_id' => $autre->id, 'status' => 'running']);

        $this->getJson('/api/v1/jobs', $this->auth())
            ->assertOk()
            ->assertJsonPath('paging.total', 2)
            ->assertJsonCount(2, 'data');
    }

    public function test_la_liste_filtre_les_jobs_en_cours(): void
    {
        GatewayJob::create(['public_id' => Str::uuid(), 'type' => 'simple', 'app_token_id' => $this->token->id, 'status' => 'pending']);
        GatewayJob::create(['public_id' => Str::uuid(), 'type' => 'simple', 'app_token_id' => $this->token->id, 'status' => 'running']);
        GatewayJob::create(['public_id' => Str::uuid(), 'type' => 'simple', 'app_token_id' => $this->token->id, 'status' => 'done', 'finished_at' => now()]);

        $this->getJson('/api/v1/jobs?status=in_progress', $this->auth())
            ->assertOk()
            ->assertJsonPath('paging.total', 2); // pending + running, pas le done
    }
}
