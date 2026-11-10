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

    /** GET .../Items... — articles simulés, au format réel de l'API EBP (champs Items). */
    public static function items(): array
    {
        return [
            'results' => [
                [
                    'Id' => 'ART001', 'Caption' => 'Motoréducteur 0,75 kW', 'DesComClear' => 'Motoréducteur 0,75 kW',
                    'SalePriceVatExcluded' => '420.00000000', 'SalePriceVatIncluded' => '504.00000000',
                    'BarCode' => '3660619000012', 'ShelfRef' => 'A-12', 'FamilyId' => 'FAR00001',
                ],
                [
                    'Id' => 'ART002', 'Caption' => 'Chaîne de transmission', 'DesComClear' => 'Chaîne de transmission',
                    'SalePriceVatExcluded' => '85.50000000', 'SalePriceVatIncluded' => '102.60000000',
                    'BarCode' => '3660619000029', 'ShelfRef' => 'B-04', 'FamilyId' => 'FAR00002',
                ],
                [
                    'Id' => 'ART003', 'Caption' => 'Variateur de fréquence', 'DesComClear' => 'Variateur de fréquence',
                    'SalePriceVatExcluded' => '640.00000000', 'SalePriceVatIncluded' => '768.00000000',
                    'BarCode' => '3660619000036', 'ShelfRef' => 'C-01', 'FamilyId' => 'FAR00001',
                ],
            ],
            'paging' => ['offset' => 0, 'limit' => 100, 'total' => 3],
        ];
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
