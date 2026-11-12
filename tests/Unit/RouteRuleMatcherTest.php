<?php

namespace Tests\Unit;

use App\Models\AppTokenRouteRule;
use App\Services\Apps\RouteRuleMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests du moteur de matching des règles d'accès par jeton.
 *
 * Pas de base : on instancie des AppTokenRouteRule non persistés (le matcher ne lit que
 * ->methods et ->pattern).
 */
class RouteRuleMatcherTest extends TestCase
{
    private RouteRuleMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new RouteRuleMatcher;
    }

    /** Fabrique une règle non persistée. */
    private function rule(array $methods, string $pattern): AppTokenRouteRule
    {
        $rule = new AppTokenRouteRule;
        $rule->methods = $methods;
        $rule->pattern = $pattern;

        return $rule;
    }

    // --- Littéral exact ---

    public function test_chemin_litteral_exact_autorise(): void
    {
        $rules = [$this->rule(['GET'], 'gescom/api/v1/Clients')];

        $this->assertTrue($this->matcher->allows($rules, 'GET', 'gescom/api/v1/Clients'));
    }

    public function test_chemin_litteral_different_refuse(): void
    {
        $rules = [$this->rule(['GET'], 'gescom/api/v1/Clients')];

        $this->assertFalse($this->matcher->allows($rules, 'GET', 'gescom/api/v1/Items'));
    }

    public function test_chemin_plus_long_que_le_motif_refuse(): void
    {
        $rules = [$this->rule(['GET'], 'gescom/api/v1/Clients')];

        $this->assertFalse($this->matcher->allows($rules, 'GET', 'gescom/api/v1/Clients/42'));
    }

    public function test_chemin_plus_court_que_le_motif_refuse(): void
    {
        $rules = [$this->rule(['GET'], 'gescom/api/v1/Clients')];

        $this->assertFalse($this->matcher->allows($rules, 'GET', 'gescom/api/v1'));
    }

    // --- Joker * (un segment) ---

    public function test_etoile_matche_exactement_un_segment(): void
    {
        $rules = [$this->rule(['GET'], 'gescom/api/v1/Folders/*/Items')];

        $this->assertTrue($this->matcher->allows($rules, 'GET', 'gescom/api/v1/Folders/3691/Items'));
    }

    public function test_etoile_ne_matche_pas_zero_segment(): void
    {
        $rules = [$this->rule(['GET'], 'gescom/api/v1/Folders/*/Items')];

        // Il manque le segment couvert par * → le chemin est trop court.
        $this->assertFalse($this->matcher->allows($rules, 'GET', 'gescom/api/v1/Folders/Items'));
    }

    public function test_etoile_ne_matche_pas_plusieurs_segments(): void
    {
        $rules = [$this->rule(['GET'], 'gescom/api/v1/Folders/*/Items')];

        $this->assertFalse($this->matcher->allows($rules, 'GET', 'gescom/api/v1/Folders/3691/extra/Items'));
    }

    public function test_plusieurs_etoiles(): void
    {
        $rules = [$this->rule(['GET'], 'gescom/api/v1/Folders/*/Items/*')];

        $this->assertTrue($this->matcher->allows($rules, 'GET', 'gescom/api/v1/Folders/3691/Items/ART-1'));
        $this->assertFalse($this->matcher->allows($rules, 'GET', 'gescom/api/v1/Folders/3691/Items'));
    }

    // --- Joker ** (reste du chemin) ---

    public function test_double_etoile_matche_zero_segment(): void
    {
        $rules = [$this->rule(['GET'], 'gescom/api/v1/**')];

        $this->assertTrue($this->matcher->allows($rules, 'GET', 'gescom/api/v1'));
    }

    public function test_double_etoile_matche_un_segment(): void
    {
        $rules = [$this->rule(['GET'], 'gescom/api/v1/**')];

        $this->assertTrue($this->matcher->allows($rules, 'GET', 'gescom/api/v1/Clients'));
    }

    public function test_double_etoile_matche_plusieurs_segments(): void
    {
        $rules = [$this->rule(['GET'], 'gescom/api/v1/**')];

        $this->assertTrue($this->matcher->allows($rules, 'GET', 'gescom/api/v1/Folders/3691/Items/ART-1'));
    }

    public function test_double_etoile_ne_deborde_pas_du_prefixe(): void
    {
        $rules = [$this->rule(['GET'], 'gescom/api/v1/**')];

        // Autre module : ne doit pas matcher.
        $this->assertFalse($this->matcher->allows($rules, 'GET', 'batiment/api/v1/Clients'));
    }

    public function test_double_etoile_apres_etoile(): void
    {
        $rules = [$this->rule(['GET'], 'gescom/api/v1/Folders/*/**')];

        $this->assertTrue($this->matcher->allows($rules, 'GET', 'gescom/api/v1/Folders/3691/Items/ART-1'));
        $this->assertTrue($this->matcher->allows($rules, 'GET', 'gescom/api/v1/Folders/3691'));
        // Le segment couvert par * manque.
        $this->assertFalse($this->matcher->allows($rules, 'GET', 'gescom/api/v1/Folders'));
    }

    // --- Verbes ---

    public function test_verbe_hors_liste_refuse(): void
    {
        $rules = [$this->rule(['GET'], 'gescom/api/v1/Clients')];

        $this->assertFalse($this->matcher->allows($rules, 'POST', 'gescom/api/v1/Clients'));
    }

    public function test_verbe_dans_liste_autorise(): void
    {
        $rules = [$this->rule(['GET', 'POST'], 'gescom/api/v1/Clients')];

        $this->assertTrue($this->matcher->allows($rules, 'GET', 'gescom/api/v1/Clients'));
        $this->assertTrue($this->matcher->allows($rules, 'POST', 'gescom/api/v1/Clients'));
        $this->assertFalse($this->matcher->allows($rules, 'DELETE', 'gescom/api/v1/Clients'));
    }

    public function test_verbe_insensible_a_la_casse(): void
    {
        $rules = [$this->rule(['get'], 'gescom/api/v1/Clients')];

        $this->assertTrue($this->matcher->allows($rules, 'get', 'gescom/api/v1/Clients'));
        $this->assertTrue($this->matcher->allows($rules, 'GET', 'gescom/api/v1/Clients'));
    }

    // --- Casse du chemin (alignée sur EbpPath, sensible à la casse) ---

    public function test_chemin_sensible_a_la_casse(): void
    {
        $rules = [$this->rule(['GET'], 'gescom/api/v1/Clients')];

        $this->assertFalse($this->matcher->allows($rules, 'GET', 'gescom/api/v1/clients'));
    }

    // --- Normalisation trailing / leading / double slash ---

    #[DataProvider('cheminsEquivalents')]
    public function test_slashs_normalises(string $pattern, string $path): void
    {
        $rules = [$this->rule(['GET'], $pattern)];

        $this->assertTrue($this->matcher->allows($rules, 'GET', $path));
    }

    public static function cheminsEquivalents(): array
    {
        return [
            'trailing slash chemin' => ['gescom/api/v1/Clients', 'gescom/api/v1/Clients/'],
            'leading slash chemin' => ['gescom/api/v1/Clients', '/gescom/api/v1/Clients'],
            'trailing slash motif' => ['gescom/api/v1/Clients/', 'gescom/api/v1/Clients'],
            'leading slash motif' => ['/gescom/api/v1/Clients', 'gescom/api/v1/Clients'],
            'double slash chemin' => ['gescom/api/v1/Clients', 'gescom//api/v1//Clients'],
        ];
    }

    // --- Plusieurs règles en OU ---

    public function test_plusieurs_regles_une_seule_suffit(): void
    {
        $rules = [
            $this->rule(['GET'], 'gescom/api/v1/Clients'),
            $this->rule(['GET'], 'gescom/api/v1/Items/**'),
        ];

        $this->assertTrue($this->matcher->allows($rules, 'GET', 'gescom/api/v1/Items/ItemsExtentedQuery'));
        $this->assertTrue($this->matcher->allows($rules, 'GET', 'gescom/api/v1/Clients'));
        $this->assertFalse($this->matcher->allows($rules, 'GET', 'gescom/api/v1/Deals'));
    }

    public function test_aucune_regle_naccorde_rien(): void
    {
        // Avec une liste vide, allows() refuse toujours : la sémantique « défaut ouvert » est
        // portée par le middleware (qui n'appelle le matcher QUE s'il y a des règles).
        $this->assertFalse($this->matcher->allows([], 'GET', 'gescom/api/v1/Clients'));
    }

    // --- Cas limites ---

    public function test_pattern_entierement_double_etoile_matche_tout(): void
    {
        $rules = [$this->rule(['GET'], '**')];

        $this->assertTrue($this->matcher->allows($rules, 'GET', 'gescom/api/v1/Clients'));
        $this->assertTrue($this->matcher->allows($rules, 'GET', 'batiment/api/v1/Folders/1/X'));
    }

    public function test_methods_vide_refuse(): void
    {
        $rules = [$this->rule([], 'gescom/api/v1/Clients')];

        $this->assertFalse($this->matcher->allows($rules, 'GET', 'gescom/api/v1/Clients'));
    }
}
