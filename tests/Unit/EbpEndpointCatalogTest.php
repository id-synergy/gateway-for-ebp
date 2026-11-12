<?php

namespace Tests\Unit;

use App\Services\Ebp\EbpEndpointCatalog;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\TestCase;

class EbpEndpointCatalogTest extends TestCase
{
    /** Spec OpenAPI minimale écrite dans un fichier temporaire pour isoler le parsing. */
    private function specWith(string $yaml): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ebp_spec_').'.yaml';
        file_put_contents($path, $yaml);

        return $path;
    }

    protected function tearDown(): void
    {
        Cache::clearResolvedInstances();
        parent::tearDown();
    }

    private function catalog(string $path): EbpEndpointCatalog
    {
        // Cache array en mémoire, sans dépendance au framework complet.
        Cache::swap(new Repository(new ArrayStore));

        return new EbpEndpointCatalog($path);
    }

    public function test_parse_les_endpoints_et_leurs_verbes(): void
    {
        $spec = $this->specWith(<<<'YAML'
        openapi: 3.0.3
        paths:
          /gescom/api/v1/Folders:
            get:
              summary: Lister les dossiers
          /gescom/api/v1/Folders/{FolderId}/Clients:
            get:
              summary: Obtenir les clients
            post:
              summary: Créer un client
        YAML);

        $groups = $this->catalog($spec)->groups();

        $this->assertCount(1, $groups);
        $this->assertSame('gescom/api/v1', $groups[0]['module']);
        $this->assertCount(2, $groups[0]['endpoints']);

        // Tri par chemin : Folders avant Folders/{FolderId}/Clients.
        $clients = collect($groups[0]['endpoints'])->firstWhere('path', 'gescom/api/v1/Folders/{FolderId}/Clients');
        $this->assertSame(['GET', 'POST'], $clients['methods']);
        $this->assertTrue($clients['hasFolder']);
        $this->assertSame('Obtenir les clients', $clients['summary']);
    }

    public function test_exclut_le_flux_dauthentification(): void
    {
        $spec = $this->specWith(<<<'YAML'
        openapi: 3.0.3
        paths:
          /connect/token:
            post:
              summary: Jeton OAuth
          /gescom/api/v1/Folders:
            get:
              summary: Dossiers
        YAML);

        $groups = $this->catalog($spec)->groups();
        $paths = collect($groups)->flatMap(fn ($g) => collect($g['endpoints'])->pluck('path'))->all();

        $this->assertContains('gescom/api/v1/Folders', $paths);
        $this->assertNotContains('connect/token', $paths);
    }

    public function test_groupe_par_module_produit(): void
    {
        $spec = $this->specWith(<<<'YAML'
        openapi: 3.0.3
        paths:
          /gescom/api/v1/Folders:
            get: { summary: A }
          /hubbix-gctpe/api/public/v1/customers:
            get: { summary: B }
          /batiment/api/v1/Folders/{FolderId}/Items:
            get: { summary: C }
        YAML);

        $groups = $this->catalog($spec)->groups();
        $modules = collect($groups)->pluck('module')->all();

        $this->assertEqualsCanonicalizing(
            ['gescom/api/v1', 'hubbix-gctpe/api/public', 'batiment/api/v1'],
            $modules,
        );
    }

    public function test_injecte_le_folderid_quand_un_dossier_est_fourni(): void
    {
        $spec = $this->specWith(<<<'YAML'
        openapi: 3.0.3
        paths:
          /gescom/api/v1/Folders/{FolderId}/Clients:
            get: { summary: Clients }
          /gescom/api/v1/Dependencies/{TableName}:
            get: { summary: Dépendances }
        YAML);

        $groups = $this->catalog($spec)->groupsForFolder('405032');
        $paths = collect($groups)->flatMap(fn ($g) => collect($g['endpoints'])->pluck('path'))->all();

        // {FolderId} remplacé, les autres {param} intacts.
        $this->assertContains('gescom/api/v1/Folders/405032/Clients', $paths);
        $this->assertContains('gescom/api/v1/Dependencies/{TableName}', $paths);
    }

    public function test_laisse_le_folderid_sans_dossier(): void
    {
        $spec = $this->specWith(<<<'YAML'
        openapi: 3.0.3
        paths:
          /gescom/api/v1/Folders/{FolderId}/Clients:
            get: { summary: Clients }
        YAML);

        $groups = $this->catalog($spec)->groupsForFolder(null);
        $this->assertSame('gescom/api/v1/Folders/{FolderId}/Clients', $groups[0]['endpoints'][0]['path']);
    }

    public function test_renvoie_vide_si_le_fichier_absent(): void
    {
        $this->assertSame([], $this->catalog('/chemin/inexistant.yaml')->groups());
    }

    public function test_injection_statique_ne_touche_que_le_folderid(): void
    {
        $this->assertSame(
            'gescom/api/v1/Folders/405032/Documents/{Type}',
            EbpEndpointCatalog::injectFolder('gescom/api/v1/Folders/{FolderId}/Documents/{Type}', '405032'),
        );
    }
}
