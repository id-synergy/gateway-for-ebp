<?php

namespace App\Services\Apps;

use App\Models\AppTokenRouteRule;
use Illuminate\Support\Collection;

/**
 * Décide si un appel (verbe + chemin EBP) est autorisé par les règles d'accès d'un jeton.
 *
 * Un motif décrit un chemin EBP relayé, segment par segment, avec deux jokers :
 *   - `*`  matche EXACTEMENT un segment (une portion entre deux « / », non vide) ;
 *   - `**` matche LE RESTE du chemin : zéro, un ou plusieurs segments. Il n'a de sens qu'en
 *          dernière position du motif (un `**` au milieu se comporte comme « tout le reste »).
 *
 * La comparaison est faite sur le chemin tel que l'app l'appelle (le `$request->path()` que
 * manipule déjà EbpPath), AVANT toute réécriture ou injection de FolderId par le proxy.
 * Elle est SENSIBLE À LA CASSE, comme EbpPath::matchPrefix (les segments EBP sont en CamelCase).
 *
 * Le verbe de la requête doit figurer (insensible à la casse) dans les `methods` de la règle
 * qui matche le chemin. Les règles d'un jeton sont en OU : une seule suffit à autoriser.
 */
class RouteRuleMatcher
{
    /**
     * L'appel (méthode + chemin) est-il autorisé par au moins une des règles ?
     *
     * @param  iterable<AppTokenRouteRule>  $rules
     */
    public function allows(iterable $rules, string $method, string $path): bool
    {
        $method = strtoupper($method);
        $segments = $this->segments($path);

        foreach ($rules as $rule) {
            if ($this->ruleAllows($rule, $method, $segments)) {
                return true;
            }
        }

        return false;
    }

    /** Une règle précise autorise-t-elle l'appel (verbe ET chemin) ? */
    public function ruleAllows(AppTokenRouteRule $rule, string $method, array|string $pathOrSegments): bool
    {
        if (! $this->methodAllowed($rule, $method)) {
            return false;
        }

        $segments = is_array($pathOrSegments) ? $pathOrSegments : $this->segments($pathOrSegments);

        return $this->patternMatches($rule->pattern, $segments);
    }

    /** Le verbe est-il dans la liste (insensible à la casse) des verbes de la règle ? */
    private function methodAllowed(AppTokenRouteRule $rule, string $method): bool
    {
        $method = strtoupper($method);

        foreach ((array) $rule->methods as $allowed) {
            if (strtoupper(trim((string) $allowed)) === $method) {
                return true;
            }
        }

        return false;
    }

    /**
     * Le motif matche-t-il la suite de segments du chemin ?
     *
     * Parcours segment par segment : un segment littéral doit être égal (casse comprise),
     * `*` consomme un segment quelconque, `**` consomme tout le reste et clôt le motif.
     */
    private function patternMatches(string $pattern, array $segments): bool
    {
        $patternSegments = $this->segments($pattern);

        $i = 0; // index dans le motif
        $j = 0; // index dans le chemin

        while ($i < count($patternSegments)) {
            $token = $patternSegments[$i];

            if ($token === '**') {
                // Reste du chemin : consomme zéro ou plusieurs segments. On ignore un éventuel
                // suffixe de motif après `**` (cas de saisie dégénéré) : `**` absorbe la fin.
                return true;
            }

            // Plus de segment de chemin à matcher alors que le motif en attend un → échec.
            if ($j >= count($segments)) {
                return false;
            }

            if ($token !== '*' && $token !== $segments[$j]) {
                return false;
            }

            $i++;
            $j++;
        }

        // Motif épuisé : il faut que le chemin le soit aussi (pas de segment en trop).
        return $j === count($segments);
    }

    /**
     * Découpe un chemin ou un motif en segments, en ignorant les « / » de tête/queue et les
     * segments vides (double slash, trailing slash). "gescom/api/v1/" → ["gescom","api","v1"].
     *
     * @return array<int,string>
     */
    private function segments(string $path): array
    {
        $path = trim($path, '/');

        if ($path === '') {
            return [];
        }

        return array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
    }

    /** Normalise des règles brutes (venant du formulaire) pour comparaison/stockage. */
    public function normalizeRules(iterable $rules): Collection
    {
        return collect($rules)->map(fn ($r) => [
            'methods' => array_values(array_unique(array_map(
                fn ($m) => strtoupper(trim((string) $m)),
                (array) ($r['methods'] ?? []),
            ))),
            'pattern' => trim((string) ($r['pattern'] ?? ''), '/'),
        ]);
    }
}
