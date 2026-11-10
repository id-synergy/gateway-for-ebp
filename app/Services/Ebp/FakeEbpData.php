<?php

namespace App\Services\Ebp;

/**
 * Données factices du mode démo (serveur EBP simulé).
 *
 * Source unique, partagée par le contrôleur HTTP {@see \App\Http\Controllers\FakeEbp\FakeEbpController}
 * (pour un test en appel direct) et par le court-circuit démo des services EBP
 * (EbpTokenService, EbpFolderDiscovery, EbpClient), qui renvoient ces réponses SANS appel réseau.
 *
 * Pas d'appel HTTP en démo : le flux complet de la gateway est testable sans identifiants EBP
 * et sans dépendre de la topologie du serveur (un `php artisan serve` mono-worker interbloque
 * sur un appel HTTP qui reboucle vers lui-même).
 *
 * Les décimales à 28 chiffres reproduisent le format `decimal` renvoyé par l'API EBP réelle.
 */
class FakeEbpData
{
    /** Réponse OAuth (échange de code ou refresh) : tokens simulés. */
    public static function tokenResponse(): array
    {
        return [
            'access_token' => 'demo_access_'.bin2hex(random_bytes(6)),
            'refresh_token' => 'demo_refresh_'.bin2hex(random_bytes(6)),
            'expires_in' => 3600,
            'refresh_expires_in' => 30 * 24 * 3600,
            'token_type' => 'Bearer',
        ];
    }

    /** GET /Folders — dossiers simulés. */
    public static function folders(): array
    {
        return [
            'folders' => [
                ['id' => '1001', 'name' => 'Société Démo', 'shortName' => 'DEMO40'],
                ['id' => '1002', 'name' => 'Succursale Démo', 'shortName' => 'DEMO30'],
            ],
            'paging' => ['offset' => 0, 'limit' => 100, 'total' => 2],
        ];
    }

    /** GET .../Clients — clients simulés. */
    public static function clients(): array
    {
        return [
            'results' => [
                ['id' => 'C0001', 'name' => 'Dupont Menuiserie', 'email' => 'contact@dupont.test', 'soldeEchu' => '1250.0000000000000000000000000'],
                ['id' => 'C0002', 'name' => 'Martin Électricité', 'email' => 'info@martin.test', 'soldeEchu' => '0.0000000000000000000000000'],
                ['id' => 'C0003', 'name' => 'Durand SARL', 'email' => 'compta@durand.test', 'soldeEchu' => '3480.5000000000000000000000000'],
            ],
            'paging' => ['offset' => 0, 'limit' => 100, 'total' => 3],
        ];
    }

    /**
     * GET .../Items... — articles simulés, au format réel de l'API EBP (champs Items).
     *
     * En mode démo, on reproduit le comportement d'EBP : la gateway relaie `WhereCondition`,
     * `Offset` et `Limit` ; le serveur EBP filtre et pagine. On applique ici le même traitement
     * sur le jeu de données factice, pour tester filtres et pagination de bout en bout sans EBP.
     *
     * @param  array<string,mixed>  $query
     */
    public static function items(array $query = []): array
    {
        $all = self::itemsCatalog();

        $filtered = self::applyWhereCondition($all, (string) ($query['WhereCondition'] ?? ''));
        $total = count($filtered);

        $offset = max(0, (int) ($query['Offset'] ?? 0));
        $limit = (int) ($query['Limit'] ?? 100);
        $limit = $limit > 0 ? min($limit, 100) : 100;

        $page = array_slice($filtered, $offset, $limit);

        return [
            'results' => array_values($page),
            'paging' => [
                'offset' => $offset,
                'limit' => $limit,
                'total' => $total,
                'returned' => count($page),
            ],
        ];
    }

    /**
     * Catalogue factice complet (avant filtrage/pagination).
     * Trois articles « vedettes » avec des EAN connus (pour tester le scan), puis un lot
     * généré d'articles variés (familles, prix, stock, état) pour démontrer la pagination
     * et les filtres sur un volume réaliste.
     */
    private static function itemsCatalog(): array
    {
        $items = [
            [
                'Id' => 'ART001', 'Caption' => 'Motoréducteur 0,75 kW', 'DesComClear' => 'Motoréducteur 0,75 kW',
                'SalePriceVatExcluded' => '420.00000000', 'SalePriceVatIncluded' => '504.00000000',
                'BarCode' => '3660619000012', 'ShelfRef' => 'A-12', 'FamilyId' => 'FAR00001',
                'SubFamilyId' => 'SF001', 'RealStock' => '5.00000000', 'ActiveState' => 0,
            ],
            [
                'Id' => 'ART002', 'Caption' => 'Chaîne de transmission', 'DesComClear' => 'Chaîne de transmission',
                'SalePriceVatExcluded' => '85.50000000', 'SalePriceVatIncluded' => '102.60000000',
                'BarCode' => '3660619000029', 'ShelfRef' => 'B-04', 'FamilyId' => 'FAR00002',
                'SubFamilyId' => 'SF002', 'RealStock' => '0.00000000', 'ActiveState' => 0,
            ],
            [
                'Id' => 'ART003', 'Caption' => 'Variateur de fréquence', 'DesComClear' => 'Variateur de fréquence',
                'SalePriceVatExcluded' => '640.00000000', 'SalePriceVatIncluded' => '768.00000000',
                'BarCode' => '3660619000036', 'ShelfRef' => 'C-01', 'FamilyId' => 'FAR00001',
                'SubFamilyId' => 'SF001', 'RealStock' => '12.00000000', 'ActiveState' => 1,
            ],
        ];

        // Lot généré, déterministe (pas d'aléa : liste stable entre deux appels).
        $labels = [
            'Roulement à billes', 'Courroie trapézoïdale', 'Pignon acier', 'Vérin pneumatique',
            'Capteur inductif', 'Moteur asynchrone', 'Réducteur à vis', 'Accouplement élastique',
            'Galet tendeur', 'Boîtier de dérivation', 'Contacteur tripolaire', 'Disjoncteur moteur',
            'Câble souple', 'Presse-étoupe', 'Joint torique', 'Vis sans fin', 'Poulie fonte',
            'Chaîne à rouleaux',
        ];
        $families = ['FAR00001', 'FAR00002', 'FAR00003', 'FAR00004'];
        $subFamilies = ['SF001', 'SF002', 'SF003'];

        for ($i = 4; $i <= 60; $i++) {
            $label = $labels[($i - 4) % count($labels)];
            $priceExcl = 10 + (($i * 37) % 900);           // 10 → ~909 €, déterministe
            $priceIncl = round($priceExcl * 1.2, 2);
            $stock = (($i * 7) % 5 === 0) ? 0 : (($i * 3) % 40); // ~1 sur 5 en rupture
            $active = (($i % 11) === 0) ? 1 : 0;                 // ~1 sur 11 inactif
            $items[] = [
                'Id' => sprintf('ART%03d', $i),
                'Caption' => sprintf('%s réf. %03d', $label, $i),
                'DesComClear' => sprintf('%s réf. %03d', $label, $i),
                'SalePriceVatExcluded' => sprintf('%.8f', $priceExcl),
                'SalePriceVatIncluded' => sprintf('%.8f', $priceIncl),
                'BarCode' => sprintf('36606190%05d', $i),
                'ShelfRef' => sprintf('%s-%02d', chr(65 + ($i % 6)), $i % 20),
                'FamilyId' => $families[$i % count($families)],
                'SubFamilyId' => $subFamilies[$i % count($subFamilies)],
                'RealStock' => sprintf('%.8f', $stock),
                'ActiveState' => $active,
            ];
        }

        return $items;
    }

    /**
     * Applique un sous-ensemble de la syntaxe `WhereCondition` d'EBP (OData-like) suffisant
     * pour la démo : `FamilyId eq '…'`, `SalePriceVatIncluded ge|le N`, `contains(Caption|Id,'…')`.
     * Les conditions séparées par `and` sont toutes exigées (les parenthèses sont ignorées).
     *
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    private static function applyWhereCondition(array $items, string $where): array
    {
        $where = trim($where);
        if ($where === '') {
            return $items;
        }

        // Découpe en conditions élémentaires sur « and » (hors parenthèses conservées telles quelles).
        $parts = preg_split('/\s+and\s+/i', $where) ?: [];

        foreach ($parts as $part) {
            $cond = trim($part, " \t()");
            if ($cond === '') {
                continue;
            }
            $items = array_filter($items, fn (array $item) => self::matchesCondition($item, $cond));
        }

        return array_values($items);
    }

    /** Évalue une condition élémentaire sur un article. */
    private static function matchesCondition(array $item, string $cond): bool
    {
        // FamilyId eq 'FAR00001' / SubFamilyId eq 'SF001' / BarCode eq '366...'
        if (preg_match("/^(FamilyId|SubFamilyId|BarCode)\s+eq\s+'(.*)'$/i", $cond, $m)) {
            return ($item[$m[1]] ?? '') === self::unescape($m[2]);
        }

        // ActiveState eq 0 (comparaison numérique)
        if (preg_match('/^ActiveState\s+eq\s+(-?[0-9]+)$/i', $cond, $m)) {
            return (int) ($item['ActiveState'] ?? 0) === (int) $m[1];
        }

        // Comparaison numérique sur un champ prix ou stock : ge|le|gt|lt
        if (preg_match('/^(SalePriceVatIncluded|RealStock)\s+(ge|le|gt|lt)\s+([0-9.]+)$/i', $cond, $m)) {
            $actual = (float) ($item[$m[1]] ?? 0);
            $value = (float) $m[3];

            return match (strtolower($m[2])) {
                'ge' => $actual >= $value,
                'le' => $actual <= $value,
                'gt' => $actual > $value,
                'lt' => $actual < $value,
                default => true,
            };
        }

        // contains(Caption,'texte') ou contains(Id,'texte')
        if (preg_match("/^contains\(([A-Za-z]+),'(.*)'\)$/i", $cond, $m)) {
            $field = $m[1];
            $needle = mb_strtolower(self::unescape($m[2]));
            $haystack = mb_strtolower((string) ($item[$field] ?? ''));

            return $needle === '' || str_contains($haystack, $needle);
        }

        // Condition non reconnue : ne pas exclure l'article (démo tolérante).
        return true;
    }

    /** Déséchappe les apostrophes doublées de la syntaxe OData. */
    private static function unescape(string $value): string
    {
        return str_replace("''", "'", $value);
    }

    /** GET .../Documents/{type} — documents de vente/achat simulés. */
    public static function documents(): array
    {
        return [
            'results' => [
                ['id' => 'FA0001', 'documentType' => 2, 'number' => 'FA-2026-001', 'date' => '2026-01-15', 'totalAmountVatIncluded' => '1512.0000000000000000000000000', 'dueAmount' => '0.0000000000000000000000000'],
                ['id' => 'FA0002', 'documentType' => 2, 'number' => 'FA-2026-002', 'date' => '2026-02-03', 'totalAmountVatIncluded' => '3480.5000000000000000000000000', 'dueAmount' => '3480.5000000000000000000000000'],
            ],
            'paging' => ['offset' => 0, 'limit' => 100, 'total' => 2],
        ];
    }
}
