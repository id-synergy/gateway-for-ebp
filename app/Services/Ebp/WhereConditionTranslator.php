<?php

namespace App\Services\Ebp;

use Illuminate\Support\Facades\Log;

/**
 * Traduit le paramètre de requête `WhereCondition` vers le filtre personnalisé YAML d'EBP.
 *
 * Les apps clientes émettent `WhereCondition` en syntaxe OData (`BarCode eq '…'`,
 * `(ActiveState eq 0) and (RealStock gt 0)`, `contains(Caption,'Tung') or contains(Id,'Tung')`).
 * Or l'API EBP SaaS Gestion n'accepte PAS l'OData : elle exige un filtre YAML `CustomFilter` /
 * `ImbricationFilter` (doc officielle, annexe « filtre personnalisé »). Toute requête OData
 * relayée telle quelle renvoie donc un 500 côté EBP.
 *
 * Ce service accepte les DEUX formats :
 *  - une valeur déjà au format YAML EBP est relayée telle quelle (passthrough) ;
 *  - une valeur OData est traduite vers l'arbre YAML équivalent.
 * Si le parsing OData échoue, la valeur est laissée inchangée (on ne casse pas la requête) et
 * un warning est journalisé.
 *
 * La valeur produite N'EST PAS URL-encodée : la couche HTTP de la gateway (EbpClient → Guzzle
 * via Http::get($url, $query)) encode déjà les paramètres de query. Encoder ici produirait un
 * double-encodage (%2520).
 *
 * Périmètre OData traduit (exactement ce que les apps émettent, cf. FakeEbpData::matchesCondition) :
 *  - `<col> eq '<str>'`                 → CustomFilter Equal, valueType String
 *  - `<col> eq <int>`                   → CustomFilter Equal, valueType Int
 *  - `<col> ne …`                       → Different
 *  - `<col> gt|lt|ge|le <num>`          → Upper | Lower | UpperOrEqual | LowerOrEqual, Decimal
 *  - `contains(<col>,'<str>')`          → Like, valueType String, value `%<str>%`
 *  - combinaisons `and` / `or` + parenthèses → arbre ImbricationFilter
 *    (`and` plus prioritaire que `or`, parenthèses explicites respectées).
 */
class WhereConditionTranslator
{
    /** Correspondance opérateur OData de comparaison → opérateur EBP. */
    private const COMPARISON_OPERATORS = [
        'eq' => 'Equal',
        'ne' => 'Different',
        'gt' => 'Upper',
        'lt' => 'Lower',
        'ge' => 'UpperOrEqual',
        'le' => 'LowerOrEqual',
    ];

    /**
     * Traduit la valeur de `WhereCondition` si elle est en OData ; relaie le YAML tel quel.
     * En cas d'échec de parsing, renvoie la valeur d'origine inchangée.
     */
    public function translate(string $whereCondition): string
    {
        $trimmed = trim($whereCondition);

        if ($trimmed === '' || $this->looksLikeYaml($trimmed)) {
            return $whereCondition;
        }

        try {
            $node = $this->parse($trimmed);

            return $this->toYaml($node, 0);
        } catch (\Throwable $e) {
            Log::warning('WhereCondition OData non traduit (laissé inchangé).', [
                'where_condition' => $whereCondition,
                'error' => $e->getMessage(),
            ]);

            return $whereCondition;
        }
    }

    /**
     * Détecte une valeur déjà au format YAML EBP : elle commence (après trim) par un mot-clé de
     * filtre (`type:`, `left:`, `column:`) ou contient un saut de ligne (le YAML EBP est multi-lignes).
     */
    private function looksLikeYaml(string $value): bool
    {
        if (str_contains($value, "\n")) {
            return true;
        }

        return (bool) preg_match('/^(type|left|column)\s*:/i', $value);
    }

    // --- Parsing OData -----------------------------------------------------------------------

    /**
     * Analyse une expression OData en un arbre de nœuds.
     *
     * @return array<string,mixed>  Nœud : ['kind'=>'custom', …] ou ['kind'=>'imbrication', …]
     */
    private function parse(string $expression): array
    {
        $tokens = $this->tokenize($expression);
        $position = 0;
        $node = $this->parseOr($tokens, $position);

        if ($position !== count($tokens)) {
            throw new \RuntimeException('Jetons OData résiduels après analyse.');
        }

        return $node;
    }

    /**
     * Découpe l'expression en jetons : parenthèses, `and`/`or`, et conditions élémentaires.
     * Les conditions (`col eq '…'`, `contains(col,'…')`) sont capturées comme un seul jeton,
     * apostrophes et parenthèses de `contains(...)` comprises.
     *
     * @return array<int,string>
     */
    private function tokenize(string $expression): array
    {
        $tokens = [];
        $length = strlen($expression);
        $i = 0;

        while ($i < $length) {
            $char = $expression[$i];

            if (ctype_space($char)) {
                $i++;

                continue;
            }

            // Parenthèse de groupement : seulement si elle n'ouvre pas un contains(...).
            if ($char === '(') {
                $tokens[] = '(';
                $i++;

                continue;
            }

            if ($char === ')') {
                $tokens[] = ')';
                $i++;

                continue;
            }

            // Opérateurs logiques `and` / `or` (délimités par des espaces/parenthèses).
            if (preg_match('/\G(and|or)\b/iA', substr($expression, $i), $m)) {
                $tokens[] = strtolower($m[1]);
                $i += strlen($m[1]);

                continue;
            }

            // Condition élémentaire : on lit jusqu'au prochain séparateur logique ou parenthèse
            // de groupement de haut niveau, en respectant les apostrophes et les contains(...).
            $condition = $this->readCondition($expression, $i);
            $tokens[] = trim($condition);
        }

        return $tokens;
    }

    /**
     * Lit une condition élémentaire à partir de $i (avancé par référence) jusqu'au prochain
     * `and`/`or` de haut niveau ou `)` de groupement. Ignore le contenu des apostrophes et
     * la paire de parenthèses d'un `contains(...)`.
     */
    private function readCondition(string $expression, int &$i): string
    {
        $length = strlen($expression);
        $start = $i;
        $inString = false;
        $parenDepth = 0;

        while ($i < $length) {
            $char = $expression[$i];

            if ($inString) {
                // Apostrophe doublée '' = apostrophe littérale : on avance de deux.
                if ($char === "'" && ($i + 1 < $length) && $expression[$i + 1] === "'") {
                    $i += 2;

                    continue;
                }
                if ($char === "'") {
                    $inString = false;
                }
                $i++;

                continue;
            }

            if ($char === "'") {
                $inString = true;
                $i++;

                continue;
            }

            if ($char === '(') {
                $parenDepth++;
                $i++;

                continue;
            }

            if ($char === ')') {
                // Parenthèse de groupement de haut niveau : fin de la condition.
                if ($parenDepth === 0) {
                    break;
                }
                $parenDepth--;
                $i++;

                continue;
            }

            // Séparateur logique de haut niveau : fin de la condition.
            if ($parenDepth === 0 && preg_match('/\G\s+(and|or)\b/iA', substr($expression, $i))) {
                break;
            }

            $i++;
        }

        return substr($expression, $start, $i - $start);
    }

    /**
     * Grammaire : OR a la plus faible priorité (`and` lie plus fort que `or`).
     *
     * @param  array<int,string>  $tokens
     * @return array<string,mixed>
     */
    private function parseOr(array $tokens, int &$position): array
    {
        $left = $this->parseAnd($tokens, $position);

        while (($tokens[$position] ?? null) === 'or') {
            $position++;
            $right = $this->parseAnd($tokens, $position);
            $left = ['kind' => 'imbrication', 'operator' => 'Or', 'left' => $left, 'right' => $right];
        }

        return $left;
    }

    /**
     * @param  array<int,string>  $tokens
     * @return array<string,mixed>
     */
    private function parseAnd(array $tokens, int &$position): array
    {
        $left = $this->parsePrimary($tokens, $position);

        while (($tokens[$position] ?? null) === 'and') {
            $position++;
            $right = $this->parsePrimary($tokens, $position);
            $left = ['kind' => 'imbrication', 'operator' => 'And', 'left' => $left, 'right' => $right];
        }

        return $left;
    }

    /**
     * Terme primaire : groupe parenthésé ou condition élémentaire.
     *
     * @param  array<int,string>  $tokens
     * @return array<string,mixed>
     */
    private function parsePrimary(array $tokens, int &$position): array
    {
        $token = $tokens[$position] ?? null;

        if ($token === null) {
            throw new \RuntimeException('Expression OData incomplète.');
        }

        if ($token === '(') {
            $position++;
            $node = $this->parseOr($tokens, $position);
            if (($tokens[$position] ?? null) !== ')') {
                throw new \RuntimeException('Parenthèse fermante manquante.');
            }
            $position++;

            return $node;
        }

        if ($token === ')' || $token === 'and' || $token === 'or') {
            throw new \RuntimeException("Jeton OData inattendu : {$token}.");
        }

        $position++;

        return $this->parseCondition($token);
    }

    /**
     * Traduit une condition élémentaire OData en nœud CustomFilter.
     *
     * @return array<string,mixed>
     */
    private function parseCondition(string $condition): array
    {
        $condition = trim($condition);

        // contains(<col>,'<str>') → Like %<str>%
        if (preg_match("/^contains\(\s*([A-Za-z_][A-Za-z0-9_.]*)\s*,\s*'(.*)'\s*\)$/s", $condition, $m)) {
            return [
                'kind' => 'custom',
                'column' => $m[1],
                'operator' => 'Like',
                'valueType' => 'String',
                'value' => '%'.$this->unescape($m[2]).'%',
            ];
        }

        // <col> <op> '<str>' → valueType String
        if (preg_match("/^([A-Za-z_][A-Za-z0-9_.]*)\s+(eq|ne|gt|lt|ge|le)\s+'(.*)'$/s", $condition, $m)) {
            return [
                'kind' => 'custom',
                'column' => $m[1],
                'operator' => self::COMPARISON_OPERATORS[strtolower($m[2])],
                'valueType' => 'String',
                'value' => $this->unescape($m[3]),
            ];
        }

        // <col> eq|ne <int> → valueType Int (entier nu)
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_.]*)\s+(eq|ne)\s+(-?[0-9]+)$/', $condition, $m)) {
            return [
                'kind' => 'custom',
                'column' => $m[1],
                'operator' => self::COMPARISON_OPERATORS[strtolower($m[2])],
                'valueType' => 'Int',
                'value' => $m[3],
            ];
        }

        // <col> gt|lt|ge|le <num> → valueType Decimal (comparaison numérique)
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_.]*)\s+(gt|lt|ge|le)\s+(-?[0-9]+(?:\.[0-9]+)?)$/', $condition, $m)) {
            return [
                'kind' => 'custom',
                'column' => $m[1],
                'operator' => self::COMPARISON_OPERATORS[strtolower($m[2])],
                'valueType' => 'Decimal',
                'value' => $m[3],
            ];
        }

        throw new \RuntimeException("Condition OData non reconnue : {$condition}.");
    }

    /** Déséchappe les apostrophes doublées de la syntaxe OData (`''` → `'`). */
    private function unescape(string $value): string
    {
        return str_replace("''", "'", $value);
    }

    // --- Sérialisation YAML EBP --------------------------------------------------------------

    /**
     * Sérialise un nœud en YAML EBP, indenté de 2 espaces par niveau.
     *
     * @param  array<string,mixed>  $node
     */
    private function toYaml(array $node, int $depth): string
    {
        return $node['kind'] === 'imbrication'
            ? $this->imbricationToYaml($node, $depth)
            : $this->customToYaml($node, $depth);
    }

    /**
     * @param  array<string,mixed>  $node
     */
    private function customToYaml(array $node, int $depth): string
    {
        $pad = str_repeat('  ', $depth);
        $lines = [
            $pad.'type: CustomFilter',
            $pad.'column: '.$node['column'],
            $pad.'operator: '.$node['operator'],
            $pad.'valueType: '.$node['valueType'],
            $pad.'value:',
            $pad."  - '".$this->escapeYamlValue((string) $node['value'])."'",
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  array<string,mixed>  $node
     */
    private function imbricationToYaml(array $node, int $depth): string
    {
        $pad = str_repeat('  ', $depth);
        $lines = [
            $pad.'type: ImbricationFilter',
            $pad.'operator: '.$node['operator'],
            $pad.'left:',
            $this->toYaml($node['left'], $depth + 1),
            $pad.'right:',
            $this->toYaml($node['right'], $depth + 1),
        ];

        return implode("\n", $lines);
    }

    /** Échappe une valeur pour une chaîne YAML entre apostrophes (`'` → `''`). */
    private function escapeYamlValue(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
