<?php

namespace Tests\Feature;

use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Services\Apps\AppTokenService;
use App\Services\Ebp\EbpTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Vérifie que CHAQUE module EBP (les 7 préfixes autorisés) est bien relayé vers l'API EBP,
 * avec le bon chemin, et que le FolderId est injecté là où il le faut (URL ou corps).
 */
class ProxyRelayPerModuleTest extends TestCase
{
    use RefreshDatabase;

    private string $plain;

    private EbpFolder $folder;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['ebp.quota.per_second' => 1000]);

        $account = EbpAccount::factory()->create(['is_active' => true]);
        Http::fake([config('ebp.token_url') => Http::response(['access_token' => 'A1', 'refresh_token' => 'R1', 'expires_in' => 3600])]);
        app(EbpTokenService::class)->exchangeCode($account, 'CODE');

        $this->folder = EbpFolder::factory()->create(['ebp_account_id' => $account->id, 'folder_id' => '3691']);
        [, $this->plain] = app(AppTokenService::class)->create('App', $account->fresh(), $this->folder);
    }

    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->plain}"];
    }

    /**
     * Pour chaque module, une URL témoin de la gateway → le chemin EBP attendu en sortie.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function modules(): array
    {
        return [
            'gescom'      => ['/gescom/api/v1/Clients', '/gescom/api/v1/Folders/3691/Clients'],
            'gctpe'       => ['/hubbix-gctpe/api/public/v1/customers/C1', '/hubbix-gctpe/api/public/v1/customers/C1'],
            'cpttpe'      => ['/hubbix-cpttpe/api/v1/journals', '/hubbix-cpttpe/api/v1/journals'],
            'batiment'    => ['/batiment/api/v1/Clients', '/batiment/api/v1/Folders/3691/Clients'],
            'import'      => ['/import/api/v1/ModelSettings', '/import/api/v1/ModelSettings'],
            'export'      => ['/export/api/v1/Status', '/export/api/v1/Status'],
            'maintenance' => ['/maintenance/api/v1/Folders', '/maintenance/api/v1/Folders'],
        ];
    }

    #[DataProvider('modules')]
    public function test_chaque_module_est_relaye_vers_ebp(string $gatewayPath, string $expectedEbpPath): void
    {
        Http::fake(['*' => Http::response(['results' => []], 200)]);

        $this->getJson($gatewayPath, $this->auth())->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), $expectedEbpPath));
    }

    public function test_un_module_hors_liste_blanche_nest_pas_relaye(): void
    {
        $this->getJson('/inconnu/api/v1/Truc', $this->auth())->assertStatus(404);
    }

    public function test_le_folderid_est_injecte_dans_l_url_quand_absent(): void
    {
        Http::fake(['*/Folders/3691/Clients*' => Http::response(['results' => []])]);

        $this->getJson('/gescom/api/v1/Clients', $this->auth())->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/gescom/api/v1/Folders/3691/Clients'));
    }

    public function test_le_folderid_est_injecte_dans_le_corps_pour_import(): void
    {
        Http::fake(['*/import/api/v1/ModelSettings*' => Http::response(['id' => 'X'], 201)]);

        // L'app n'envoie PAS folderId dans le corps : la gateway doit l'ajouter.
        $this->postJson('/import/api/v1/ModelSettings', ['importSettings' => 'S'], $this->auth())
            ->assertStatus(201);

        Http::assertSent(fn ($r) => ($r->data()['folderId'] ?? null) === '3691');
    }

    public function test_un_folderid_fourni_dans_le_corps_est_respecte(): void
    {
        Http::fake(['*/export/api/v1/CreateExport/*' => Http::response(['ok' => true], 200)]);

        // L'app fournit son propre folderId : la gateway ne l'écrase pas.
        $this->postJson('/export/api/v1/CreateExport/pdf', ['folderId' => '9999', 'kind' => 'Item'], $this->auth())
            ->assertOk();

        Http::assertSent(fn ($r) => ($r->data()['folderId'] ?? null) === '9999');
    }
}
