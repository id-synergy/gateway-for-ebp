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
            'gescom' => ['/gescom/api/v1/Clients', '/gescom/api/v1/Folders/3691/Clients'],
            'gctpe' => ['/hubbix-gctpe/api/public/v1/customers/C1', '/hubbix-gctpe/api/public/v1/customers/C1'],
            'cpttpe' => ['/hubbix-cpttpe/api/v1/journals', '/hubbix-cpttpe/api/v1/journals'],
            'batiment' => ['/batiment/api/v1/Clients', '/batiment/api/v1/Folders/3691/Clients'],
            'import' => ['/import/api/v1/ModelSettings', '/import/api/v1/ModelSettings'],
            'export' => ['/export/api/v1/Status', '/export/api/v1/Status'],
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

    public function test_l_endpoint_des_articles_est_relaye_tel_quel(): void
    {
        // Nos specs/catalogues utilisent désormais l'orthographe réellement servie par EBP
        // (« ItemsExtentedQuery ») : plus aucune réécriture, la gateway relaie le chemin tel quel.
        Http::fake(['*' => Http::response(['results' => []], 200)]);

        $this->getJson('/gescom/api/v1/Items/ItemsExtentedQuery', $this->auth())->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/Items/ItemsExtentedQuery'));
    }

    public function test_un_wherecondition_odata_est_traduit_en_yaml_ebp_avant_relai(): void
    {
        Http::fake(['*' => Http::response(['results' => []], 200)]);

        // L'app envoie l'OData ; la gateway doit relayer le YAML CustomFilter correspondant.
        $this->getJson('/gescom/api/v1/Items/ItemsExtentedQuery?WhereCondition='.rawurlencode("BarCode eq '366061900001'"), $this->auth())
            ->assertOk();

        Http::assertSent(function ($r) {
            $sent = urldecode($r->url());

            return str_contains($sent, 'type: CustomFilter')
                && str_contains($sent, 'column: BarCode')
                && str_contains($sent, 'operator: Equal')
                && str_contains($sent, 'valueType: String')
                && str_contains($sent, "- '366061900001'")
                // Pas de syntaxe OData résiduelle dans ce qui part vers EBP.
                && ! str_contains($sent, "eq '366061900001'");
        });
    }

    public function test_un_wherecondition_odata_imbrique_est_traduit_en_imbricationfilter(): void
    {
        Http::fake(['*' => Http::response(['results' => []], 200)]);

        $this->getJson('/gescom/api/v1/Items/ItemsExtentedQuery?WhereCondition='.rawurlencode('(ActiveState eq 0) and (RealStock gt 0)'), $this->auth())
            ->assertOk();

        Http::assertSent(function ($r) {
            $sent = urldecode($r->url());

            return str_contains($sent, 'type: ImbricationFilter')
                && str_contains($sent, 'operator: And')
                && str_contains($sent, 'column: ActiveState')
                && str_contains($sent, 'operator: Upper');
        });
    }

    public function test_un_wherecondition_deja_en_yaml_est_relaye_tel_quel(): void
    {
        Http::fake(['*' => Http::response(['results' => []], 200)]);

        $yaml = "type: CustomFilter\ncolumn: BarCode\noperator: Equal\nvalueType: String\nvalue:\n  - '123'";
        $this->getJson('/gescom/api/v1/Items/ItemsExtentedQuery?WhereCondition='.rawurlencode($yaml), $this->auth())
            ->assertOk();

        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), $yaml));
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

    public function test_le_folderid_est_injecte_en_query_pour_les_modules_plats(): void
    {
        // Import/Export attendent le FolderId en query : la gateway doit l'ajouter quand l'app
        // ne l'a pas mis (cas L3/L4 du rapport : 400 côté EBP faute de FolderId).
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $this->getJson('/import/api/v1/ModelSettings', $this->auth())->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'FolderId=3691'));
    }

    public function test_createimport_est_relaye_en_multipart_avec_le_fichier(): void
    {
        // Bug principal du rapport : un upload (multipart) était envoyé à EBP en application/json,
        // fichier perdu, d'où « Incorrect Content-Type: application/json » (500). La gateway doit
        // relayer un VRAI multipart, fichier compris.
        Http::fake(['*/import/api/v1/CreateImport*' => Http::response(['importId' => 'IMP1'], 202)]);

        $csv = "référence;EAN13\n150/40/SB;1234567891012\n";
        $file = \Illuminate\Http\Testing\File::createWithContent('import-ean.csv', $csv);

        $this->post(
            '/import/api/v1/CreateImport?ImportCategory=Items&ImportSettings=article%20SR',
            ['file' => $file],
            $this->auth(),
        )->assertStatus(202);

        Http::assertSent(function ($r) {
            $contentType = $r->header('Content-Type')[0] ?? '';
            $raw = (string) $r->body();
            $url = urldecode($r->url());

            return str_contains($r->url(), '/import/api/v1/CreateImport')
                && str_contains($contentType, 'multipart/form-data')   // plus de application/json
                && str_contains($raw, 'name="file"')                   // le fichier est bien présent
                && str_contains($raw, 'filename="import-ean.csv"')
                && str_contains($raw, '150/40/SB')                     // contenu du fichier relayé
                // Les paramètres ImportCategory/ImportSettings restent en query (attendu par EBP).
                && str_contains($url, 'ImportCategory=Items')
                && str_contains($url, 'ImportSettings=article SR');
        });
    }

    public function test_le_message_d_erreur_ebp_est_remonte_a_l_app(): void
    {
        // Avant : seul ebpStatus était renvoyé. Maintenant on remonte aussi le message d'EBP
        // (hors StackTrace), pour que l'app puisse diagnostiquer.
        $ebpError = json_encode([
            'title' => 'EBP_IMPORT_INTERNAL_SERVER_ERROR_TITLE',
            'errorType' => 'ERROR_MIDDLEWARE',
            'errors' => [
                ['errorCode' => 'ERROR', 'errorMessage' => 'Incorrect Content-Type: application/json'],
                ['errorCode' => 'StackTrace', 'errorMessage' => '   at Secret.Internal.Path()'],
            ],
        ]);
        Http::fake(['*/import/api/v1/CreateImport*' => Http::response($ebpError, 500)]);

        $file = \Illuminate\Http\Testing\File::createWithContent('x.csv', "a;b\n1;2\n");
        $response = $this->post(
            '/import/api/v1/CreateImport?ImportCategory=Items&ImportSettings=article%20SR',
            ['file' => $file],
            $this->auth(),
        )->assertStatus(502);

        $response->assertJsonPath('ebpStatus', 500);
        $response->assertJsonPath('ebpError', 'Incorrect Content-Type: application/json');
        // La trace interne d'EBP ne doit jamais fuiter vers l'app.
        $this->assertStringNotContainsString('Secret.Internal.Path', $response->getContent());
    }
}
