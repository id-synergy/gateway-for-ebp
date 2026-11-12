<?php

namespace App\Services\Ebp;

use App\Services\Apps\RoutePatternCatalog;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Yaml\Yaml;

/**
 * Catalogue des endpoints EBP CONCRETS, pour l'autocomplétion du champ endpoint du testeur.
 *
 * Contrairement à {@see RoutePatternCatalog} (qui normalise les chemins pour
 * les règles d'accès : retire Folders/{id} et pose des jokers `*`), ce catalogue conserve les
 * chemins tels qu'EBP les expose, avec leurs {paramètres} visibles — dont {FolderId} — pour que
 * l'utilisateur puisse les copier puis compléter à la main.
 *
 * Source : openapi/ebp-reference.yaml (consolidation des 7 specs officielles EBP). Les endpoints
 * sont groupés par préfixe produit (gescom/api/v1, hubbix-gctpe/api/public, batiment/api/v1, …),
 * avec leurs verbes et un résumé. Le flux d'authentification (/connect/*) est exclu.
 *
 * Le parsing du YAML est mis en cache, invalidé quand le fichier change (mtime).
 */
class EbpEndpointCatalog
{
    /** Verbes HTTP exploités par le testeur, dans l'ordre d'affichage. */
    private const VERBS = ['get', 'post', 'put', 'patch', 'delete'];

    public function __construct(
        private readonly ?string $specPath = null,
    ) {}

    /**
     * Endpoints groupés par module produit.
     *
     * @return array<int,array{module:string,prefix:string,endpoints:array<int,array{path:string,methods:array<int,string>,summary:string,hasFolder:bool}>}>
     */
    public function groups(): array
    {
        $path = $this->specPath ?? base_path('openapi/ebp-reference.yaml');

        if (! is_file($path)) {
            return [];
        }

        $key = 'ebp_endpoint_catalog:'.md5($path).':'.filemtime($path);

        return Cache::remember($key, now()->addDay(), fn () => $this->build($path));
    }

    /**
     * Mêmes endpoints, mais avec {FolderId} déjà remplacé par un identifiant de dossier concret.
     * Les chemins sans {FolderId} (modules plats) sont renvoyés inchangés.
     *
     * @return array<int,array<string,mixed>>
     */
    public function groupsForFolder(?string $folderId): array
    {
        $groups = $this->groups();

        if ($folderId === null || $folderId === '') {
            return $groups;
        }

        return array_map(function ($group) use ($folderId) {
            $group['endpoints'] = array_map(function ($endpoint) use ($folderId) {
                $endpoint['path'] = self::injectFolder($endpoint['path'], $folderId);

                return $endpoint;
            }, $group['endpoints']);

            return $group;
        }, $groups);
    }

    /**
     * Remplace le segment {FolderId} d'un chemin par un identifiant concret.
     * N'affecte que le paramètre de dossier (les autres {param} sont laissés tels quels).
     */
    public static function injectFolder(string $path, string $folderId): string
    {
        return preg_replace('#\{FolderId\}#', $folderId, $path) ?? $path;
    }

    /** @return array<int,array<string,mixed>> */
    private function build(string $file): array
    {
        $spec = Yaml::parseFile($file);
        $paths = $spec['paths'] ?? [];

        $byModule = [];

        foreach ($paths as $rawPath => $operations) {
            if (! is_array($operations)) {
                continue;
            }

            // Endpoint relatif, sans slash de tête (format de saisie du testeur).
            $clean = ltrim((string) $rawPath, '/');

            // Flux d'authentification OAuth : hors périmètre du testeur.
            if (str_starts_with($clean, 'connect/')) {
                continue;
            }

            $methods = $this->methods($operations);
            if ($methods === []) {
                continue;
            }

            $module = $this->moduleOf($clean);

            $byModule[$module][] = [
                'path' => $clean,
                'methods' => $methods,
                'summary' => $this->summaryOf($operations),
                'hasFolder' => str_contains($clean, '{FolderId}'),
            ];
        }

        // Tri des modules par nom, et des endpoints par chemin à l'intérieur de chaque module.
        ksort($byModule);

        $out = [];
        foreach ($byModule as $module => $endpoints) {
            usort($endpoints, fn ($a, $b) => strcmp($a['path'], $b['path']));

            $out[] = [
                'module' => $module,
                'prefix' => $module,
                'endpoints' => $endpoints,
            ];
        }

        return $out;
    }

    /**
     * Préfixe produit d'un chemin EBP : les segments qui précèdent la première version (v1,
     * public, …) incluse, ex. « gescom/api/v1 » ou « hubbix-gctpe/api/public ». Repli : les
     * deux premiers segments.
     */
    private function moduleOf(string $path): string
    {
        $segments = explode('/', $path);

        $prefix = [];
        foreach ($segments as $segment) {
            $prefix[] = $segment;
            // On s'arrête au segment de version (vN) ou « public », qui clôt le préfixe produit.
            if (preg_match('/^v\d+$/', $segment) || $segment === 'public') {
                return implode('/', $prefix);
            }
        }

        return implode('/', array_slice($segments, 0, 2));
    }

    /**
     * Premier résumé non vide parmi les opérations d'un chemin.
     *
     * @param  array<string,mixed>  $operations
     */
    private function summaryOf(array $operations): string
    {
        foreach ($operations as $op) {
            if (is_array($op) && ! empty($op['summary'])) {
                return (string) $op['summary'];
            }
        }

        return '';
    }

    /**
     * Verbes HTTP déclarés pour un chemin, dans l'ordre canonique.
     *
     * @param  array<string,mixed>  $operations
     * @return array<int,string>
     */
    private function methods(array $operations): array
    {
        $found = [];
        foreach (self::VERBS as $verb) {
            if (array_key_exists($verb, $operations)) {
                $found[] = strtoupper($verb);
            }
        }

        return $found;
    }
}
