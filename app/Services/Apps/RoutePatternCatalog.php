<?php

namespace App\Services\Apps;

use App\Services\Ebp\EbpPath;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Yaml\Yaml;

/**
 * Catalogue des routes EBP connues, pour alimenter les suggestions d'autocomplétion des
 * patterns de règles d'accès dans le back-office.
 *
 * Source : openapi/gateway.yaml (le même Swagger que la doc). On ne garde que les chemins
 * proxy EBP (préfixés par un module autorisé), on retire le segment Folders/{FolderId}
 * (implicite côté gateway) et on remplace les éventuels {paramètres} restants par le joker
 * `*`. Résultat : des patterns prêts à coller, groupés par module, avec leurs verbes.
 *
 * Le parsing du YAML (~600 Ko) est mis en cache ; invalidé quand le fichier change (mtime).
 */
class RoutePatternCatalog
{
    /**
     * Suggestions groupées par module.
     *
     * @return array<int,array{module:string,prefix:string,routes:array<int,array{pattern:string,methods:array<int,string>,summary:string}>}>
     */
    public function suggestions(): array
    {
        $path = base_path('openapi/gateway.yaml');

        if (! is_file($path)) {
            return [];
        }

        $key = 'route_pattern_catalog:'.md5($path).':'.filemtime($path);

        return Cache::remember($key, now()->addDay(), fn () => $this->build($path));
    }

    /** @return array<int,array<string,mixed>> */
    private function build(string $file): array
    {
        $spec = Yaml::parseFile($file);
        $paths = $spec['paths'] ?? [];

        $prefixes = array_map(fn ($p) => trim((string) $p, '/'), (array) config('ebp.gateway.proxy.allowed_prefixes', []));

        // Regroupe les patterns par module (préfixe), en dédoublonnant.
        $byModule = [];

        foreach ($paths as $rawPath => $operations) {
            $clean = ltrim((string) $rawPath, '/');

            // Ne garde que les chemins proxy EBP (un préfixe de module matche).
            $prefix = EbpPath::matchPrefix($clean);
            if ($prefix === null) {
                continue;
            }

            $pattern = $this->toPattern($clean);
            $methods = $this->methods($operations);
            if ($methods === []) {
                continue;
            }

            $summary = '';
            foreach ($operations as $op) {
                if (is_array($op) && ! empty($op['summary'])) {
                    $summary = (string) $op['summary'];
                    break;
                }
            }

            // Dédoublonne : la variante plate et la variante /Folders/{id}/ convergent vers le
            // même pattern une fois le dossier retiré. On fusionne alors les verbes.
            $existing = $byModule[$prefix][$pattern] ?? null;
            $byModule[$prefix][$pattern] = [
                'pattern' => $pattern,
                'methods' => array_values(array_unique(array_merge($existing['methods'] ?? [], $methods))),
                'summary' => $existing['summary'] ?? $summary,
            ];
        }

        $out = [];
        foreach ($prefixes as $prefix) {
            if (empty($byModule[$prefix])) {
                continue;
            }
            $routes = array_values($byModule[$prefix]);
            usort($routes, fn ($a, $b) => strcmp($a['pattern'], $b['pattern']));

            $out[] = [
                'module' => $prefix,
                'prefix' => $prefix,
                'routes' => $routes,
            ];
        }

        return $out;
    }

    /**
     * Transforme un chemin OpenAPI en pattern de règle :
     *  - retire le segment « Folders/{FolderId} » (dossier implicite côté gateway) ;
     *  - remplace tout {paramètre} restant par le joker `*`.
     */
    private function toPattern(string $path): string
    {
        // Retire « Folders/{...} » n'importe où dans le chemin (le FolderId est implicite).
        $path = preg_replace('#Folders/\{[^/}]+\}/?#', '', $path);
        $path = (string) $path;

        // {param} -> *
        $path = preg_replace('#\{[^/}]+\}#', '*', $path);

        return trim((string) $path, '/');
    }

    /**
     * Verbes HTTP déclarés pour un chemin OpenAPI.
     *
     * @param  array<string,mixed>  $operations
     * @return array<int,string>
     */
    private function methods(array $operations): array
    {
        $verbs = ['get', 'post', 'put', 'patch', 'delete'];
        $found = [];

        foreach ($verbs as $verb) {
            if (array_key_exists($verb, $operations)) {
                $found[] = strtoupper($verb);
            }
        }

        return $found;
    }
}
