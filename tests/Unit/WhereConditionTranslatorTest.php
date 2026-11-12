<?php

namespace Tests\Unit;

use App\Services\Ebp\WhereConditionTranslator;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Traducteur `WhereCondition` : OData → filtre YAML EBP, passthrough du YAML, tolérance aux
 * valeurs non reconnues. La syntaxe YEBP produite est confirmée contre l'API EBP réelle
 * (CustomFilter Equal/Like, ImbricationFilter And/Or).
 */
class WhereConditionTranslatorTest extends TestCase
{
    private WhereConditionTranslator $translator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->translator = new WhereConditionTranslator();
    }

    public function test_eq_sur_chaine_donne_un_customfilter_equal_string(): void
    {
        $yaml = $this->translator->translate("BarCode eq '366061900001'");

        $this->assertSame(
            "type: CustomFilter\ncolumn: BarCode\noperator: Equal\nvalueType: String\nvalue:\n  - '366061900001'",
            $yaml,
        );
    }

    public function test_eq_sur_entier_donne_un_customfilter_equal_int(): void
    {
        $yaml = $this->translator->translate('ActiveState eq 0');

        $this->assertSame(
            "type: CustomFilter\ncolumn: ActiveState\noperator: Equal\nvalueType: Int\nvalue:\n  - '0'",
            $yaml,
        );
    }

    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function comparisonOperators(): array
    {
        return [
            // OData op, EBP op, valueType attendu
            'ne chaine'      => ["Caption ne 'X'", 'Different', 'String'],
            'gt numerique'   => ['RealStock gt 0', 'Upper', 'Decimal'],
            'lt numerique'   => ['RealStock lt 10', 'Lower', 'Decimal'],
            'ge numerique'   => ['SalePriceVatIncluded ge 100', 'UpperOrEqual', 'Decimal'],
            'le numerique'   => ['SalePriceVatIncluded le 500.5', 'LowerOrEqual', 'Decimal'],
        ];
    }

    #[DataProvider('comparisonOperators')]
    public function test_operateurs_de_comparaison(string $odata, string $expectedOperator, string $expectedType): void
    {
        $yaml = $this->translator->translate($odata);

        $this->assertStringContainsString("operator: {$expectedOperator}", $yaml);
        $this->assertStringContainsString("valueType: {$expectedType}", $yaml);
    }

    public function test_contains_donne_un_customfilter_like_avec_jokers(): void
    {
        $yaml = $this->translator->translate("contains(Caption,'Tung')");

        $this->assertSame(
            "type: CustomFilter\ncolumn: Caption\noperator: Like\nvalueType: String\nvalue:\n  - '%Tung%'",
            $yaml,
        );
    }

    public function test_and_donne_une_imbrication_and(): void
    {
        $yaml = $this->translator->translate("(ActiveState eq 0) and (RealStock gt 0)");

        $expected = implode("\n", [
            'type: ImbricationFilter',
            'operator: And',
            'left:',
            '  type: CustomFilter',
            '  column: ActiveState',
            '  operator: Equal',
            '  valueType: Int',
            '  value:',
            "    - '0'",
            'right:',
            '  type: CustomFilter',
            '  column: RealStock',
            '  operator: Upper',
            '  valueType: Decimal',
            '  value:',
            "    - '0'",
        ]);

        $this->assertSame($expected, $yaml);
    }

    public function test_or_donne_une_imbrication_or(): void
    {
        $yaml = $this->translator->translate("contains(Caption,'Tung') or contains(Id,'Tung')");

        $this->assertStringStartsWith("type: ImbricationFilter\noperator: Or\nleft:", $yaml);
        $this->assertStringContainsString("  column: Caption\n  operator: Like", $yaml);
        $this->assertStringContainsString("  column: Id\n  operator: Like", $yaml);
    }

    public function test_and_est_plus_prioritaire_que_or(): void
    {
        // a or b and c  ==  a or (b and c) : la racine est un Or dont la branche droite est un And.
        $yaml = $this->translator->translate("A eq '1' or B eq '2' and C eq '3'");

        $this->assertStringStartsWith("type: ImbricationFilter\noperator: Or\n", $yaml);
        $this->assertStringContainsString("  type: ImbricationFilter\n  operator: And", $yaml);
    }

    public function test_parentheses_explicites_changent_le_groupement(): void
    {
        // (a or b) and c : la racine est un And dont la branche gauche est un Or.
        $yaml = $this->translator->translate("(A eq '1' or B eq '2') and C eq '3'");

        $this->assertStringStartsWith("type: ImbricationFilter\noperator: And\n", $yaml);
        $this->assertStringContainsString("  type: ImbricationFilter\n  operator: Or", $yaml);
    }

    public function test_le_yaml_deja_formate_est_relaye_tel_quel(): void
    {
        $yaml = "type: CustomFilter\ncolumn: BarCode\noperator: Equal\nvalueType: String\nvalue:\n  - '123'";

        $this->assertSame($yaml, $this->translator->translate($yaml));
    }

    public function test_le_yaml_sur_une_ligne_commencant_par_type_est_relaye_tel_quel(): void
    {
        $value = "type: CustomFilter";

        $this->assertSame($value, $this->translator->translate($value));
    }

    public function test_apostrophe_dans_une_valeur_est_echappee(): void
    {
        // OData échappe l'apostrophe en la doublant ; le YAML aussi.
        $yaml = $this->translator->translate("Caption eq 'L''Atelier'");

        $this->assertStringContainsString("  - 'L''Atelier'", $yaml);
    }

    public function test_valeur_vide_reste_vide(): void
    {
        $this->assertSame('', $this->translator->translate(''));
    }

    public function test_valeur_non_reconnue_est_laissee_intacte_et_journalisee(): void
    {
        Log::spy();

        $input = 'startswith(Caption,"x")'; // fonction OData hors périmètre
        $this->assertSame($input, $this->translator->translate($input));

        Log::shouldHaveReceived('warning')->once();
    }
}
